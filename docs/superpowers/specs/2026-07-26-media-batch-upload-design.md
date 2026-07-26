# Media Upload Controls and Gallery Batch Upload

## Goal

Make media selection feel consistent with Soapkraft and reduce repetitive work for users who already have several images prepared.

The upload modal remains a focused single-image workflow. Batch upload is available only from the full Media Library, where users intentionally manage workspace media.

## Scope

### Upload modals

- Replace the browser-native file control with a compact Soapkraft control.
- Keep one selected file per upload.
- Show the selected filename beside the branded choose button.
- Preserve the existing upload, processing, automatic selection, error, and retry behavior.

### Full Media Library

- Replace the browser-native file control with the same compact visual vocabulary.
- Allow selecting up to five images at once.
- Show every selected filename in a removable row.
- If more than five files are selected, show the entire selection and explain how many must be removed.
- Disable the upload action until the selection contains five or fewer valid files.

## Batch Upload Flow

1. The user selects one to five images in the full Media Library.
2. The interface lists the selected files and allows individual removal.
3. Files transfer sequentially, one HTTP request per file.
4. Each file retains the existing 10 MB application limit.
5. Every successful transfer creates its normal `media` queue job.
6. The existing worker processes jobs one at a time.
7. The Media Library polls processing assets and displays them through the existing cards.

The Forge/PHP request limit therefore applies to each file, not to the combined batch size. Selecting five 10 MB files does not create one 50 MB request.

## Progress and Errors

- Show the active filename, its transfer percentage, and overall batch position such as “2 of 5”.
- Use Soapkraft’s Living Green accent for progress.
- A transfer failure is attached to that file and does not prevent later files from transferring.
- Failed files remain visible with a clear retry or remove action.
- Server-side processing failures continue to use the existing failed media-card behavior.
- Quota checks remain authoritative on the server. Where a finite plan limit applies, the interface must explain when the selection exceeds the remaining allowance.

## Resource Boundaries

- Maximum batch size: five files.
- Maximum image size: 10 MB per file.
- File transfers: sequential.
- Media processing: existing `media` queue.
- Production worker count: unchanged.
- No database schema, R2, Cloudflare, or media-authorization changes.

## Accessibility

- The hidden native input remains keyboard and screen-reader accessible through its associated label.
- The branded control has a visible focus state using the application accent.
- Selected file rows expose meaningful remove labels containing the filename.
- Transfer progress uses an accessible progressbar with current, minimum, and maximum values.
- Error messages use an alert region; status changes use a polite live region.
- Controls retain usable touch targets and responsive wrapping.

## Testing

- Modal picker contract tests confirm single-file selection and branded control markup.
- Media Library tests confirm `multiple`, five-file selection rules, removable rows, disabled over-limit submission, and branded progress.
- JavaScript contract tests confirm sequential transfer order, continued processing after one transfer failure, and per-file progress state.
- Existing media authorization, upload validation, processing, consumer integration, and accessibility tests remain green.
- The production frontend build must pass.

## Acceptance Criteria

- No browser-native blue or orange file button is visible in upload modals or the Media Library.
- Upload modals accept one file and retain their current workflow.
- The full Media Library accepts up to five files per batch.
- Selecting six files clearly explains that one must be removed and prevents submission.
- A batch transfers one file at a time and queues each valid image independently.
- The interface uses Soapkraft’s established colors, spacing, focus, and progress patterns.
