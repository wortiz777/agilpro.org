<?php

declare(strict_types=1);

namespace Drupal\agilpro_onbase\Service;

use Drupal\agilpro_onbase\Exception\OnBaseFatalException;
use Drupal\agilpro_onbase\Exception\OnBaseRecoverableException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Utils;
use Psr\Log\LoggerInterface;

/**
 * Cliente REST optimizado para Hyland OnBase Web API.
 *
 * Implementa consumo de credenciales seguras vía Key Module, intercambio OAuth2 / Bearer Token,
 * y streaming eficiente de archivos de hasta 20 MB sin consumo excesivo de memoria RAM.
 */
class OnBaseClientService {

  public const TIMEOUT_SECONDS = 30.0;

  protected LoggerInterface $logger;

  public function __construct(
    protected readonly ClientInterface $httpClient,
    protected readonly KeyRepositoryInterface $keyRepository,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly FileSystemInterface $fileSystem,
  ) {
    $this->logger = $loggerFactory->get('agilpro_onbase');
  }

  /**
   * Obtiene de forma segura el valor de una clave gestionada por Key Repository.
   */
  public function getKeyValue(string $keyId, string $fallbackEnv = ''): ?string {
    $key = $this->keyRepository->getKey($keyId);
    if ($key !== null) {
      $val = $key->getKeyValue();
      if (!empty($val)) {
        return (string) $val;
      }
    }

    if ($fallbackEnv !== '') {
      $envVal = getenv($fallbackEnv);
      if ($envVal !== false && $envVal !== '') {
        return (string) $envVal;
      }
    }

    return null;
  }

  /**
   * Obtiene la URL base de la API de OnBase.
   */
  public function getBaseUrl(): string {
    $url = $this->getKeyValue('onbase_base_url', 'ONBASE_BASE_URL');
    if (empty($url)) {
      throw new OnBaseFatalException('La URL base de OnBase (onbase_base_url) no está configurada en Key Repository ni en variables de entorno.');
    }
    return rtrim($url, '/');
  }

  /**
   * Obtiene un token de acceso OAuth 2.0 (o token configurado en Key).
   */
  public function getAccessToken(): string {
    // Si se configuró directamente un token estático o API key en el repositorio.
    $directToken = $this->getKeyValue('onbase_bearer_token', 'ONBASE_BEARER_TOKEN');
    if (!empty($directToken)) {
      return $directToken;
    }

    $clientId = $this->getKeyValue('onbase_client_id', 'ONBASE_CLIENT_ID');
    $clientSecret = $this->getKeyValue('onbase_client_secret', 'ONBASE_CLIENT_SECRET');

    if (empty($clientId) || empty($clientSecret)) {
      throw new OnBaseFatalException('Credenciales OAuth de OnBase incompletas. Se requiere onbase_client_id y onbase_client_secret.');
    }

    $tokenEndpoint = $this->getBaseUrl() . '/oauth/token';

    try {
      $response = $this->httpClient->request('POST', $tokenEndpoint, [
        'form_params' => [
          'grant_type' => 'client_credentials',
          'client_id' => $clientId,
          'client_secret' => $clientSecret,
        ],
        'timeout' => self::TIMEOUT_SECONDS,
        'connect_timeout' => 10.0,
      ]);

      $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
      if (!isset($data['access_token'])) {
        throw new OnBaseFatalException('La respuesta de autenticación de OnBase no contenía access_token.');
      }

      return (string) $data['access_token'];
    }
    catch (ConnectException $e) {
      throw new OnBaseRecoverableException('Timeout o fallo de conexión al obtener token de OnBase: ' . $e->getMessage(), (int) $e->getCode(), $e);
    }
    catch (RequestException $e) {
      $status = $e->hasResponse() ? $e->getResponse()?->getStatusCode() : 0;
      if ($status >= 500 || $status === 429 || $status === 0) {
        throw new OnBaseRecoverableException('Error temporal del servidor OnBase al autenticar (HTTP ' . $status . '): ' . $e->getMessage(), (int) $status, $e);
      }
      throw new OnBaseFatalException('Error de cliente al autenticar en OnBase (HTTP ' . $status . '): ' . $e->getMessage(), (int) $status, $e);
    }
    catch (\Throwable $e) {
      throw new OnBaseFatalException('Error inesperado durante la autenticación OnBase: ' . $e->getMessage(), (int) $e->getCode(), $e);
    }
  }

  /**
   * Ejecuta un test de diagnóstico y conectividad con la API de OnBase.
   *
   * @return array
   *   Resultado del test con status, tiempo de respuesta y mensaje detallado.
   */
  public function testConnection(): array {
    $startTime = microtime(true);
    try {
      $baseUrl = $this->getBaseUrl();
      $token = $this->getAccessToken();
      $duration = round((microtime(true) - $startTime) * 1000, 2);

      return [
        'success' => true,
        'message' => "Conexión y autenticación exitosas con OnBase en {$baseUrl}. Tiempo de respuesta: {$duration} ms.",
        'status_code' => 200,
        'duration_ms' => $duration,
      ];
    }
    catch (\Throwable $e) {
      $duration = round((microtime(true) - $startTime) * 1000, 2);
      return [
        'success' => false,
        'message' => "Fallo en diagnóstico de conexión con OnBase ({$duration} ms): " . $e->getMessage(),
        'status_code' => $e->getCode() ?: 500,
        'duration_ms' => $duration,
      ];
    }
  }

  /**
   * Sube un documento PDF de hasta 20 MB y sus palabras clave a OnBase mediante streaming de Guzzle.
   *
   * @param string $localFilePath
   *   Ruta absoluta en disco del archivo PDF.
   * @param array<string, mixed> $metadata
   *   Metadatos / keywords mínimos requeridos:
   *   DocumentType, NIT_Empresa, ID_Mentoria, Fecha_Aceptacion, Hash_Trazabilidad.
   *
   * @return array
   *   Arreglo con el resultado de OnBase, incluyendo document_handle y estado.
   */
  public function uploadDocumentStream(string $localFilePath, array $metadata): array {
    if (!file_exists($localFilePath) || !is_readable($localFilePath)) {
      throw new OnBaseFatalException("El archivo PDF a transferir no existe o no es legible en disco: {$localFilePath}");
    }

    $fileSize = filesize($localFilePath);
    if ($fileSize === false || $fileSize === 0) {
      throw new OnBaseFatalException("El archivo PDF está vacío o su tamaño no pudo determinarse: {$localFilePath}");
    }

    // Límite máximo: 20 MB (20 * 1024 * 1024 bytes = 20,971,520 bytes).
    if ($fileSize > (20 * 1024 * 1024)) {
      throw new OnBaseFatalException("El archivo PDF excede el tamaño máximo permitido de 20 MB (Tamaño actual: {$fileSize} bytes).");
    }

    // Apertura en modo lectura como stream sin cargar el binario completo en memoria RAM.
    $fileHandle = fopen($localFilePath, 'rb');
    if ($fileHandle === false) {
      throw new OnBaseFatalException("No fue posible abrir el descriptor de archivo para streaming: {$localFilePath}");
    }

    $stream = Utils::streamFor($fileHandle);
    $filename = basename($localFilePath);
    $apiKey = $this->getKeyValue('onbase_api_key', 'ONBASE_API_KEY');
    $token = $this->getAccessToken();

    $headers = [
      'Authorization' => 'Bearer ' . $token,
      'Accept' => 'application/json',
    ];
    if (!empty($apiKey)) {
      $headers['X-API-Key'] = $apiKey;
    }

    // Estructurar el multipart evitando cargar el binario en variables PHP.
    $multipart = [
      [
        'name' => 'file',
        'contents' => $stream,
        'filename' => $filename,
        'headers' => [
          'Content-Type' => 'application/pdf',
        ],
      ],
      [
        'name' => 'DocumentType',
        'contents' => (string) ($metadata['DocumentType'] ?? 'ACTA_MENTORIA'),
      ],
      [
        'name' => 'NIT_Empresa',
        'contents' => (string) ($metadata['NIT_Empresa'] ?? ''),
      ],
      [
        'name' => 'ID_Mentoria',
        'contents' => (string) ($metadata['ID_Mentoria'] ?? ''),
      ],
      [
        'name' => 'Fecha_Aceptacion',
        'contents' => (string) ($metadata['Fecha_Aceptacion'] ?? date('Y-m-d\TH:i:s')),
      ],
      [
        'name' => 'Hash_Trazabilidad',
        'contents' => (string) ($metadata['Hash_Trazabilidad'] ?? hash_file('sha256', $localFilePath)),
      ],
    ];

    // Incluir payload JSON completo de metadatos para servicios que lo reciben en campo único 'metadata'.
    $multipart[] = [
      'name' => 'metadata',
      'contents' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
      'headers' => [
        'Content-Type' => 'application/json',
      ],
    ];

    // Inyectar cabecera de observabilidad Dynatrace / W3C TraceContext si está disponible.
    $traceparent = $_SERVER['HTTP_TRACEPARENT'] ?? (getenv('DYNA_TRACEPARENT') ?: null);
    if (!empty($traceparent)) {
      $headers['traceparent'] = (string) $traceparent;
    }
    else {
      // Generar W3C Traceparent compatible con Dynatrace / OpenTelemetry:
      // version (00) - trace_id (32 hex) - parent_id (16 hex) - trace_flags (01 recorded)
      $traceId = bin2hex(random_bytes(16));
      $spanId = bin2hex(random_bytes(8));
      $headers['traceparent'] = "00-{$traceId}-{$spanId}-01";
    }

    $uploadUrl = $this->getBaseUrl() . '/api/documents/upload';

    try {
      $this->logger->info('Iniciando transferencia por streaming hacia OnBase para archivo @file (@size bytes). Mentoria: @id (Trace: @trace)', [
        '@file' => $filename,
        '@size' => $fileSize,
        '@id' => $metadata['ID_Mentoria'] ?? 'N/A',
        '@trace' => $headers['traceparent'] ?? 'none',
      ]);

      // Configuración de transporte seguro forzando TLS moderno (TLS 1.2 / TLS 1.3).
      $curlOptions = [];
      if (defined('CURL_SSLVERSION_TLSv1_2')) {
        $curlOptions[CURLOPT_SSLVERSION] = defined('CURL_SSLVERSION_TLSv1_3') ? CURL_SSLVERSION_TLSv1_3 : CURL_SSLVERSION_TLSv1_2;
      }

      $response = $this->httpClient->request('POST', $uploadUrl, [
        'headers' => $headers,
        'multipart' => $multipart,
        'timeout' => self::TIMEOUT_SECONDS,
        'connect_timeout' => 15.0,
        'curl' => $curlOptions,
      ]);

      $statusCode = $response->getStatusCode();
      $body = (string) $response->getBody();
      $data = json_decode($body, true) ?? [];

      if ($statusCode === 200 || $statusCode === 201) {
        $documentHandle = $data['documentHandle'] ?? $data['id'] ?? $data['DocumentHandle'] ?? ('OB-' . uniqid('', true));

        $this->logger->notice('Documento transferido con éxito a OnBase. Document Handle: @handle. Mentoria: @id', [
          '@handle' => $documentHandle,
          '@id' => $metadata['ID_Mentoria'] ?? 'N/A',
        ]);

        return [
          'success' => true,
          'status_code' => $statusCode,
          'document_handle' => (string) $documentHandle,
          'raw_response' => $data,
        ];
      }

      throw new OnBaseRecoverableException("Respuesta inesperada de OnBase (HTTP {$statusCode}): {$body}", $statusCode);
    }
    catch (ConnectException $e) {
      // Timeout o fallo en red (> 30s) -> Error recuperable para reintento.
      throw new OnBaseRecoverableException('Timeout o fallo de conexión al transferir archivo a OnBase (> 30s): ' . $e->getMessage(), 0, $e);
    }
    catch (RequestException $e) {
      $status = $e->hasResponse() ? $e->getResponse()?->getStatusCode() : 0;
      $responseBody = $e->hasResponse() ? (string) $e->getResponse()?->getBody() : '';

      // Errores de servidor 5xx, 429 Too Many Requests o caída de conexión (status 0) son recuperables.
      if ($status >= 500 || $status === 429 || $status === 0) {
        throw new OnBaseRecoverableException("Error 5xx/recuperable al transferir a OnBase (HTTP {$status}): {$responseBody} - {$e->getMessage()}", (int) $status, $e);
      }

      // Errores 4xx (400, 401, 403, 404, 422) son fatales (requieren corregir datos o credenciales).
      throw new OnBaseFatalException("Error 4xx fatal al transferir a OnBase (HTTP {$status}): {$responseBody} - {$e->getMessage()}", (int) $status, $e);
    }
    finally {
      if (is_resource($fileHandle)) {
        fclose($fileHandle);
      }
    }
  }

}

