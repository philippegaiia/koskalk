---
paths:
  - 'resources/views/livewire/production-bench/production/*.blade.php'
---

# Production

## Production tables use shared sticky behavior
Page-scrolling production tables use the shared sticky-table-scroll wrapper and mark thead with wire:ignore.self plus data-sticky-table-header. Keep headings on one line and set only the table-specific min-width locally. Paginated production registers use the shared 25/50/100 table-pagination control; derived preview/detail tables remain unpaginated. Recipe chooser tables in batch-size-form and task-set-form retain their bounded internal scroller and native sticky thead.

## Production detail keeps batch identity and task controls legible
Batch-material body cells use 8px vertical padding, independently of headings and inputs. Show the permanent batch number as a prominent labelled value and retain its planning reference; use the planning reference as the main identity before numbering. Task assignment controls fill the available width in a responsive grid rather than fixed narrow selects.

## Production header groups identity before operational details
Group product name and prominent labelled batch identity in the title row. Put production date, batch size, expected units and any secondary planning reference in one aligned responsive summary below; omit Source formula version from this header. Keep lifecycle progress below the summary.
