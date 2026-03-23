(function ($, Drupal, drupalSettings, once) {
  Drupal.behaviors.appointmentBookingCalendar = {
    attach: function (context, settings) {
      console.log('Attaching calendar behavior...');
      
      const elements = once('appointment-calendar', '#calendar-wrapper', context);
      
      elements.forEach(function (calendarEl) {
        console.log('Calendar container found:', calendarEl);
        
        // Remove loading message.
        $(calendarEl).empty();

        const appointmentSettings = settings.appointment || {};
        const eventsUrl = appointmentSettings.events_url;
        const adviserId = appointmentSettings.adviser_id;
        const agencyId = appointmentSettings.agency_id;

        console.log('Calendar config:', {eventsUrl, adviserId, agencyId});

        if (!eventsUrl || !adviserId || adviserId === '') {
          console.error('Missing calendar configuration:', {eventsUrl, adviserId});
          calendarEl.innerHTML = '<div class="messages messages--error">' + 
            Drupal.t('Error: Missing calendar configuration (Adviser ID: @id, URL: @url).', {
              '@id': adviserId || 'None',
              '@url': eventsUrl || 'None'
            }) + '</div>';
          return;
        }

        if (typeof FullCalendar === 'undefined') {
          console.error('FullCalendar library not loaded. Check CDN link in libraries.yml.');
          calendarEl.innerHTML = '<div class="messages messages--error">' + Drupal.t('Error: Calendar library could not be loaded.') + '</div>';
          return;
        }

        try {
          const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'timeGridWeek',
            headerToolbar: {
              left: 'prev,next today',
              center: 'title',
              right: 'timeGridWeek,timeGridDay'
            },
            slotMinTime: '08:00:00',
            slotMaxTime: '19:00:00',
            allDaySlot: false,
            selectable: true,
            selectMirror: true,
            unselectAuto: false,
            events: {
              url: eventsUrl,
              method: 'GET',
              extraParams: {
                adviser: adviserId,
                agency: agencyId
              },
              failure: function() {
                console.error('Error fetching events from URL:', eventsUrl);
              }
            },
            select: function(info) {
              const now = new Date();
              if (info.start < now) {
                alert(Drupal.t('You cannot book an appointment in the past.'));
                calendar.unselect();
                return;
              }

              const selectedDate = info.start;
              const year = selectedDate.getFullYear();
              const month = String(selectedDate.getMonth() + 1).padStart(2, '0');
              const day = String(selectedDate.getDate()).padStart(2, '0');
              const hours = String(selectedDate.getHours()).padStart(2, '0');
              const minutes = String(selectedDate.getMinutes()).padStart(2, '0');
              const seconds = '00';

              const formatted = `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
              
              const hiddenField = document.getElementById('selected-appointment-date');
              if (hiddenField) {
                hiddenField.value = formatted;
                console.log('Selected date updated:', formatted);
              }
            },
            eventDisplay: 'background',
            height: '600px',
            contentHeight: 'auto',
          });

          calendar.render();
          console.log('Calendar successfully rendered.');
        } catch (e) {
          console.error('FullCalendar initialization failed:', e);
          calendarEl.innerHTML = '<div class="messages messages--error">Error: ' + e.message + '</div>';
        }
      });
    }
  };
})(jQuery, Drupal, drupalSettings, once);
