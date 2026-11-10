/**
 * @file
 * Filtra automáticamente vistas expuestas según el campo field_camara del usuario.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.agilproCamaraFilter = {
    attach(context) {
      const settings = drupalSettings.agilpro_org || {};
      if (!settings.camara) {
        return;
      }

      const allowedPaths = Array.isArray(settings.restrictedPaths)
        ? settings.restrictedPaths
        : ['/gestion-c2-inscripcion', '/360-capacidades-emprendedoras', '/gestion-usuarios'];

      if (!allowedPaths.includes(window.location.pathname)) {
        return;
      }

      const wrappers = once(
        'agilpro-camara-wrapper',
        '#edit-field-camara-tid-wrapper, .form-item-field-camara-target-id',
        context
      );
      wrappers.forEach((wrapper) => {
        wrapper.style.display = 'none';
      });

      const selects = once(
        'agilpro-camara-select',
        '#edit-field-camara-tid, [name="field_camara_target_id"]',
        context
      );
      selects.forEach((select) => {
        if (String(select.value) !== String(settings.camara)) {
          select.value = String(settings.camara);
          select.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
    },
  };
})(Drupal, drupalSettings, once);

