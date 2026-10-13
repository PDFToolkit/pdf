# Contributor and agent instructions

## Architecture

- Keep the core limited to the PDF object model and mechanisms described by the PDF specification, including writing.
- Build explicit objects with document-scoped state. Avoid singletons, global registries and compatibility aliases.
- External PDF reading belongs to `pdftoolkit/pdf-reader`; PNG/JPEG conversion belongs to `pdftoolkit/pdf-images`; TrueType conversion belongs to `pdftoolkit/pdf-fonts`.
- Keep those modules out of production dependencies. Development dependencies may support examples and the shared documentation.

## Completion and releases

- Keep commits atomic, with a clear purpose and no WIP commits in the published history.
- Release each affected package when a coherent addition, correction or documentation update has been completed and validated. Publishing the changes on `master` alone does not complete the work.
- Follow [RELEASING.md](RELEASING.md): use independent package versions, stable dependency constraints, annotated tags and GitHub release notes.
- The initial publication of the optional packages has one bootstrap exception: the core may temporarily lock their validated development snapshots. Follow the sequence in RELEASING.md and publish the core stabilization patch with released module dependencies before completing that work. Module production dependencies must remain stable throughout.
- Before tagging, require successful checks for the exact candidate commit on PHP 8.3, 8.4 and 8.5, including the repository's independent PDF validations.
- Do not rewrite a published tag or edit an old release to include new changes. Publish a new version instead.
- When shared documentation changes, lock its optional module dependencies to published versions and verify that source links resolve to their tagged commits.
- Report the versions and release links when the work is complete.
