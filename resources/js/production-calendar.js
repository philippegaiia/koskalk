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
            const url = event.extendedProps?.url;

            if (! url) {
                return;
            }

            if (window.Livewire?.navigate) {
                window.Livewire.navigate(url);

                return;
            }

            window.location.assign(url);
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

export function createProductionCalendarComponent() {
    return {
        calendar: null,
        cleanup: null,
        refreshOnNavigate: null,

        init() {
            const options = JSON.parse(this.$el.dataset.calendarOptions ?? '{}');

            this.calendar = window.productionCalendar(this.$el, options);
            this.cleanup = window.Livewire?.on('production-calendar-updated', (payload) => {
                const events = Array.isArray(payload?.events) ? payload.events : [];
                const filters = [...this.$el.closest('[wire\\:id]')?.querySelectorAll('input[type=checkbox]') ?? []];
                const visibleFilters = {
                    showProductions: filters[0]?.checked ?? true,
                    showTasks: filters[1]?.checked ?? true,
                    showCompleted: filters[2]?.checked ?? false,
                };

                if (Object.entries(visibleFilters).some(([key, value]) => payload?.[key] !== value)) {
                    return;
                }

                this.calendar?.update(events);
            });
            this.refreshOnNavigate = () => {
                const component = this.$el.closest('[wire\\:id]');

                if (component && window.Livewire) {
                    window.Livewire.find(component.getAttribute('wire:id'))?.call('refreshEvents');
                }
            };
            document.addEventListener('livewire:navigated', this.refreshOnNavigate);
        },

        destroy() {
            this.cleanup?.();
            document.removeEventListener('livewire:navigated', this.refreshOnNavigate);
            this.calendar?.destroy();
        },
    };
}
