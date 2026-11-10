/**
 * @file
 * Lógica Javascript para operaciones en lote (VBO) del Dashboard FOSFEC.
 */

(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.fosfecDashboardVbo = {
    attach: function (context, settings) {
      // 1. Selector masivo "Seleccionar todo"
      var selectAll = once('fosfec-vbo-select-all', '#vbo-select-all', context);
      $(selectAll).on('change', function () {
        var isChecked = $(this).is(':checked');
        $('.vbo-item-check').prop('checked', isChecked);
      });

      // 2. Función auxiliar para obtener IDs seleccionados
      function getSelectedIds() {
        var selected = [];
        $('.vbo-item-check:checked').each(function () {
          selected.push($(this).val());
        });
        return selected;
      }

      // 3. Ejecutar acción masiva
      function runBulkAction(action, extraData) {
        var ids = getSelectedIds();
        if (ids.length === 0) {
          alert('Por favor seleccione al menos una inscripción de la lista marcando la casilla de verificación.');
          return;
        }

        var confirmMsg = '¿Está seguro de ejecutar esta acción sobre ' + ids.length + ' inscripción(es)?';
        if (!confirm(confirmMsg)) {
          return;
        }

        var postData = {
          bulk_action: action,
          selected_ids: ids
        };
        if (extraData) {
          $.extend(postData, extraData);
        }

        $.ajax({
          url: '/admin/agilpro/dashboard/bulk-inscripciones',
          type: 'POST',
          dataType: 'json',
          data: postData,
          success: function (res) {
            if (res.success) {
              alert(res.message);
              window.location.reload();
            } else {
              alert('Error: ' + res.message);
            }
          },
          error: function (xhr) {
            var msg = 'Ocurrió un error de red al procesar la operación en lote.';
            if (xhr.responseJSON && xhr.responseJSON.message) {
              msg = 'Error: ' + xhr.responseJSON.message;
            }
            alert(msg);
          }
        });
      }

      // 4. Click en Aprobar
      var btnAprobar = once('fosfec-vbo-aprobar', '#vbo-aprobar-btn', context);
      $(btnAprobar).on('click', function () {
        runBulkAction('aprobar');
      });

      // 5. Click en Rechazar
      var btnRechazar = once('fosfec-vbo-rechazar', '#vbo-rechazar-btn', context);
      $(btnRechazar).on('click', function () {
        runBulkAction('rechazar');
      });

      // 6. Click en Cambiar Estado
      var btnEstado = once('fosfec-vbo-estado', '#vbo-cambiar-estado-btn', context);
      $(btnEstado).on('click', function () {
        var nuevoEstado = $('#vbo-estado-select').val();
        if (!nuevoEstado) {
          alert('Por favor elija un estado del desplegable.');
          return;
        }
        runBulkAction('cambiar_estado', { nuevo_estado: nuevoEstado });
      });

      // 7. Click en Asignar Ruta
      var btnRuta = once('fosfec-vbo-ruta', '#vbo-asignar-ruta-btn', context);
      $(btnRuta).on('click', function () {
        var rutaId = $('#vbo-ruta-select').val();
        if (!rutaId) {
          alert('Por favor elija una Ruta de Atención del desplegable.');
          return;
        }
        runBulkAction('asignar_ruta', { ruta_id: rutaId });
      });

      // 8. Click en Asignar Mentor
      var btnMentor = once('fosfec-vbo-mentor', '#vbo-asignar-mentor-btn', context);
      $(btnMentor).on('click', function () {
        var mentorId = $('#vbo-mentor-select').val();
        if (!mentorId) {
          alert('Por favor elija un mentor del desplegable.');
          return;
        }
        runBulkAction('asignar_mentor', { mentor_id: mentorId });
      });
    }
  };

})(jQuery, Drupal, once);

