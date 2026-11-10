/**
 * @file
 * Lógica AJAX para Aceptación Digital en el Portal Mipyme.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.miEmpresaAceptacionDigital = {
    attach: function (context) {
      var checkboxes = once('mi-empresa-aceptacion', '.confirm-acceptance-checkbox', context);

      checkboxes.forEach(function (chk) {
        chk.addEventListener('change', function () {
          if (!this.checked) {
            return;
          }

          var sessionId = this.getAttribute('data-session-id');
          if (!sessionId) {
            return;
          }

          var confirmed = confirm('¿Está seguro de confirmar la aceptación digital de esta sesión de mentoría técnica FOSFEC? Esta acción registrará su IP y fecha como firma digital de soporte auditado.');
          if (!confirmed) {
            this.checked = false;
            return;
          }

          var parentBox = this.closest('.acceptance-action-box');
          if (parentBox) {
            parentBox.innerHTML = '<span class="text-muted"><i class="fa fa-spinner fa-spin"></i> Firmando digitalmente...</span>';
          }

          var formData = new FormData();
          formData.append('transaction_id', sessionId);

          fetch('/mi-empresa/sesion/aceptar', {
            method: 'POST',
            body: formData,
            headers: {
              'X-Requested-With': 'XMLHttpRequest'
            }
          })
          .then(function (res) {
            return res.json();
          })
          .then(function (data) {
            if (data.success) {
              if (parentBox) {
                parentBox.innerHTML = '<div class="signed-badge">✓ Aceptada Digitalmente<div class="audit-meta">IP: ' + data.ip + '<br>' + data.timestamp + '</div></div>';
              }
              alert('Aceptación digital confirmada exitosamente.');
            }
            else {
              alert('Error al confirmar: ' + (data.message || 'Error desconocido.'));
              location.reload();
            }
          })
          .catch(function (err) {
            alert('Error de conexión al procesar la firma digital.');
            console.error(err);
            location.reload();
          });
        });
      });
    }
  };

})(Drupal, once);
