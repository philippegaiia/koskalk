import {
    createCalendar,
    DayGrid,
    List,
    TimeGrid,
    destroyCalendar,
} from '@event-calendar/core';
import '@event-calendar/core/index.css';

export function createProductionCalendar(element, options = {}) {
    const calendar = createCalendar(element, [DayGrid, TimeGrid, List], {
        ...options,
        editable: false,
        eventStartEditable: false,
        eventDurationEditable: false,
        eventClick: ({ event }) => {
            if (! event.url) {
                return;
            }

            if (window.Livewire?.navigate) {
                window.Livewire.navigate(event.url);

                return;
            }

            window.location.assign(event.url);
        },
        datesSet: ({ startStr, endStr }) => {
            const component = element.closest('[wire\\:id]');

            if (component && window.Livewire) {
                window.Livewire.find(component.getAttribute('wire:id'))?.call('setRange', startStr.slice(0, 10), endStr.slice(0, 10));
            }
        },
    });

    return {
        update(events) {
            calendar.setOption('events', events);
        },
        destroy() {
            destroyCalendar(calendar);
        },
    };
}
