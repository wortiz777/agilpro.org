/**
 * @file
 * Controlador Vanilla ES6+ para navegación corporativa y componentes interactivos.
 *
 * Compatible con Drupal 11 (jQuery 4.0.0) sin depender del JS heredado de
 * Bootstrap 3.3.7 (el cual lanza excepción con jQuery >= 4).
 */
(function (Drupal, once) {
  'use strict';

  /**
   * Cierra todos los menús desplegables abiertos excepto el indicado.
   *
   * @param {HTMLElement|null} exceptLi
   */
  function closeAllDropdowns(exceptLi) {
    var openItems = document.querySelectorAll('#navbar li.dropdown.open');
    openItems.forEach(function (li) {
      if (li !== exceptLi) {
        li.classList.remove('open');
        var toggle = li.querySelector(':scope > a.dropdown-toggle');
        if (toggle) {
          toggle.setAttribute('aria-expanded', 'false');
        }
      }
    });
  }

  /**
   * Comportamiento del menú principal corporativo (hover con intención, clic y teclado).
   */
  Drupal.behaviors.agilproEnterpriseNavigation = {
    attach: function (context) {
      var navbar = once('agilpro-navbar', 'header#navbar', context);
      if (navbar.length > 0) {
        var headerEl = navbar[0];
        window.addEventListener('scroll', function () {
          if (window.scrollY > 12) {
            headerEl.classList.add('navbar--scrolled');
          }
          else {
            headerEl.classList.remove('navbar--scrolled');
          }
        }, { passive: true });
      }

      // Botón hamburguesa móvil.
      var mobileToggles = once('agilpro-nav-toggle', 'button.navbar-toggle[data-target="#navbar-collapse"]', context);
      mobileToggles.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          var target = document.getElementById('navbar-collapse');
          if (!target) {
            return;
          }
          var isOpen = target.classList.toggle('in');
          btn.classList.toggle('collapsed', !isOpen);
          btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
      });

      // Ítems con submenú desplegable.
      var dropdownItems = once('agilpro-dropdown', '#navbar li.dropdown', context);
      dropdownItems.forEach(function (li) {
        var toggle = li.querySelector(':scope > a.dropdown-toggle');
        var menu = li.querySelector(':scope > ul.dropdown-menu');
        var closeTimer = null;

        if (!toggle || !menu) {
          return;
        }

        function openDropdown() {
          if (closeTimer) {
            clearTimeout(closeTimer);
            closeTimer = null;
          }
          closeAllDropdowns(li);
          li.classList.add('open');
          toggle.setAttribute('aria-expanded', 'true');
        }

        function scheduleClose() {
          if (window.innerWidth < 992) {
            return;
          }
          closeTimer = setTimeout(function () {
            li.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
          }, 140);
        }

        // Hover en escritorio con retardo de cortesía (intent grace period).
        li.addEventListener('mouseenter', function () {
          if (window.innerWidth >= 992) {
            openDropdown();
          }
        });

        li.addEventListener('mouseleave', function () {
          if (window.innerWidth >= 992) {
            scheduleClose();
          }
        });

        // Clic o toque en escritorio y móvil.
        toggle.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          var isOpen = li.classList.contains('open');
          if (isOpen) {
            li.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
          }
          else {
            openDropdown();
          }
        });

        // Navegación por teclado accesible (Escape, ArrowDown).
        li.addEventListener('keydown', function (e) {
          if (e.key === 'Escape') {
            li.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.focus();
          }
          else if (e.key === 'ArrowDown' && document.activeElement === toggle) {
            e.preventDefault();
            openDropdown();
            var firstLink = menu.querySelector('a');
            if (firstLink) {
              firstLink.focus();
            }
          }
        });
      });

      // Cierre al hacer clic fuera de la barra de navegación.
      var docBind = once('agilpro-outside-click', 'body', context);
      if (docBind.length > 0) {
        document.addEventListener('click', function (e) {
          if (!e.target.closest('#navbar li.dropdown')) {
            closeAllDropdowns(null);
          }
        });
      }

      // Soporte nativo para paneles colapsables (Mapeo Institucional, etc.).
      var collapseLinks = once('agilpro-collapse', '[data-toggle="collapse"]', context);
      collapseLinks.forEach(function (trigger) {
        if (trigger.classList.contains('navbar-toggle')) {
          return;
        }
        trigger.addEventListener('click', function (e) {
          var selector = trigger.getAttribute('href') || trigger.getAttribute('data-target');
          if (!selector || selector.charAt(0) !== '#') {
            return;
          }
          var target = document.querySelector(selector);
          if (!target) {
            return;
          }
          e.preventDefault();
          var isVisible = target.classList.contains('in') || target.style.display === 'block';
          if (isVisible) {
            target.classList.remove('in');
            target.style.display = 'none';
          }
          else {
            target.classList.add('in');
            target.style.display = 'block';
          }
        });
      });
    }
  };

  /**
   * Comportamiento interactivo y validación amigable para matrices Likert Webform (360°).
   */
  Drupal.behaviors.agilproLikertFormHandler = {
    attach: function (context) {
      var likertTables = once('agilpro-likert-table', 'table.webform-likert-table', context);
      likertTables.forEach(function (table) {
        var form = table.closest('form');
        if (!form) {
          return;
        }

        // Asegurar novalidate para evitar que la validación nativa silenciosa del navegador cancele el submit
        form.setAttribute('novalidate', 'novalidate');

        function checkLikertValidity(e) {
          var rows = table.querySelectorAll('tbody tr');
          var unansweredRows = [];

          rows.forEach(function (row) {
            var checked = row.querySelector('input[type="radio"]:checked');
            if (!checked) {
              row.classList.add('likert-row-unanswered');
              unansweredRows.push(row);
            }
            else {
              row.classList.remove('likert-row-unanswered');
            }
          });

          if (unansweredRows.length > 0) {
            if (e) {
              e.preventDefault();
              e.stopPropagation();
            }

            showValidationBanner(table, unansweredRows.length);

            // Desplazamiento suave a la primera pregunta sin responder.
            var firstMissing = unansweredRows[0];
            firstMissing.scrollIntoView({ behavior: 'smooth', block: 'center' });
            var firstRadio = firstMissing.querySelector('input[type="radio"]');
            if (firstRadio) {
              firstRadio.focus();
            }
            return false;
          }
          else {
            var banner = document.querySelector('.likert-validation-banner');
            if (banner) {
              banner.style.display = 'none';
            }
            return true;
          }
        }

        // Clic en toda el área de la celda de respuesta activa el radiobutton correspondiente.
        table.addEventListener('click', function (e) {
          var td = e.target.closest('td');
          if (!td || td.cellIndex === 0) {
            return;
          }

          var radio = td.querySelector('input[type="radio"]');
          if (!radio) {
            return;
          }

          if (e.target !== radio) {
            radio.checked = true;
            radio.dispatchEvent(new Event('change', { bubbles: true }));
          }

          // Retirar el resaltado de error si la fila fue respondida.
          var row = td.closest('tr');
          if (row) {
            row.classList.remove('likert-row-unanswered');
          }

          // Actualizar o retirar el banner si ya no quedan preguntas pendientes.
          updateValidationBanner(table);
        });

        // Escucha en cambios directos en los radiobuttons.
        table.querySelectorAll('input[type="radio"]').forEach(function (radio) {
          radio.addEventListener('change', function () {
            var row = radio.closest('tr');
            if (row) {
              row.classList.remove('likert-row-unanswered');
            }
            updateValidationBanner(table);
          });
        });

        // Interceptar clic directo en botones de envío
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
          btn.addEventListener('click', function (e) {
            if (!checkLikertValidity(e)) {
              return false;
            }
          });
        });

        // Validación visual antes de enviar el formulario.
        form.addEventListener('submit', function (e) {
          if (!checkLikertValidity(e)) {
            return false;
          }
        });
      });

      function showValidationBanner(table, count) {
        var existing = document.querySelector('.likert-validation-banner');
        var form = table.closest('form');
        if (!existing) {
          existing = document.createElement('div');
          existing.className = 'likert-validation-banner alert alert-danger';
          existing.setAttribute('role', 'alert');
          if (form) {
            form.insertBefore(existing, form.firstChild);
          }
          else {
            table.parentElement.insertBefore(existing, table);
          }
        }
        existing.innerHTML = '<div style="display:flex; align-items:center; gap:14px; width:100%;"><i class="fa fa-exclamation-triangle" style="font-size:24px; color:#dc2626; flex-shrink:0;"></i> <div><strong style="font-size:16px; color:#991b1b; display:block; margin-bottom:3px;">Atención: faltan preguntas por responder</strong>Quedan <strong style="color:#b91c1c; font-size:15px;">' + count + '</strong> pregunta(s) obligatorias sin responder destacadas en color rojo. Por favor selecciona tu respuesta para poder enviar tu diagnóstico.</div></div>';
        existing.style.display = 'block';
      }

      function updateValidationBanner(table) {
        var unanswered = table.querySelectorAll('tbody tr.likert-row-unanswered');
        var banner = document.querySelector('.likert-validation-banner');
        if (!banner) {
          return;
        }
        if (unanswered.length === 0) {
          banner.style.display = 'none';
        }
        else {
          banner.innerHTML = '<div style="display:flex; align-items:center; gap:14px; width:100%;"><i class="fa fa-exclamation-triangle" style="font-size:24px; color:#dc2626; flex-shrink:0;"></i> <div><strong style="font-size:16px; color:#991b1b; display:block; margin-bottom:3px;">Atención: faltan preguntas por responder</strong>Quedan <strong style="color:#b91c1c; font-size:15px;">' + unanswered.length + '</strong> pregunta(s) obligatorias sin responder destacadas en color rojo. Por favor selecciona tu respuesta para poder enviar tu diagnóstico.</div></div>';
          banner.style.display = 'block';
        }
      }
    }
  };

})(Drupal, once);


/**
 * Comportamiento interactivo del menú vertical lateral izquierdo (expandir/colapsar).
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.agilproVerticalNav = {
    attach: function (context) {
      var sidebar = document.getElementById('agilpro-sidebar-nav');
      if (!sidebar) {
        return;
      }

      var toggleBtn = document.getElementById('agilpro-sidebar-toggle');
      if (toggleBtn) {
        once('agilpro-vnav-toggle', toggleBtn).forEach(function (btn) {
          // Recuperar estado previo de localStorage
          var isExpanded = localStorage.getItem('agilpro.vnav.expanded') === 'true';
          if (isExpanded && window.innerWidth >= 1024) {
            sidebar.classList.add('is-expanded');
            document.body.classList.add('agilpro-vnav-expanded');
            btn.setAttribute('aria-expanded', 'true');
          }

          btn.addEventListener('click', function (e) {
            e.preventDefault();
            var currentlyExpanded = sidebar.classList.contains('is-expanded');
            if (currentlyExpanded) {
              sidebar.classList.remove('is-expanded');
              document.body.classList.remove('agilpro-vnav-expanded');
              btn.setAttribute('aria-expanded', 'false');
              localStorage.setItem('agilpro.vnav.expanded', 'false');
            }
            else {
              sidebar.classList.add('is-expanded');
              document.body.classList.add('agilpro-vnav-expanded');
              btn.setAttribute('aria-expanded', 'true');
              localStorage.setItem('agilpro.vnav.expanded', 'true');
            }
          });
        });
      }

      // Manejo de enlaces y submenús en la barra lateral
      var subToggles = once('agilpro-vmenu-subtoggle', '.agilpro-submenu-toggle', sidebar);
      subToggles.forEach(function (link) {
        link.addEventListener('click', function (e) {
          var targetHref = link.getAttribute('href');
          var isArrow = e.target.classList.contains('submenu-arrow');

          if (!sidebar.classList.contains('is-expanded')) {
            // En modo colapsado (solo iconos): Si tiene un enlace real distinto de '#', navegar directamente a él
            if (targetHref && targetHref !== '#' && !isArrow) {
              return; // Dejar que el navegador siga el enlace
            }
            // Si no tiene enlace o hace clic en la flecha, expandir la barra lateral
            sidebar.classList.add('is-expanded');
            document.body.classList.add('agilpro-vnav-expanded');
            if (toggleBtn) {
              toggleBtn.setAttribute('aria-expanded', 'true');
            }
            localStorage.setItem('agilpro.vnav.expanded', 'true');
          }

          // En modo expandido: si se hace clic en la flecha o si el link no tiene destino ('#')
          // se abre/cierra el submenú en acordeón
          if (isArrow || !targetHref || targetHref === '#') {
            e.preventDefault();
            e.stopPropagation();
            var parentLi = link.closest('.agilpro-vmenu-item');
            if (parentLi) {
              var isOpen = parentLi.classList.toggle('is-open');
              link.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            }
          }
        });
      });
    }
  };

})(Drupal, once);
