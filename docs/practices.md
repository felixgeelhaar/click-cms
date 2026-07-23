# How this codebase is worked on

Two practices are claimed for this project: domain-driven design and
test-driven development. One of them is largely honoured. The other has not
been, and pretending otherwise would make this document worthless.

## Domain-driven design

### What holds

**The domain performs no I/O.** Checked, not assumed: nothing under `src/Domain`
reads a file, opens a socket, touches a superglobal or sends a header. That is
what lets `Content`, `ContentKey`, `Locale`, `Version`, `PublicationState` and
`ImageQuality` be tested without fixtures.

**The models are not anemic.** Behaviour sits with the data it concerns.
`RetentionPolicy` decides what to discard; `PublicationState` derives what
"published" means; `ImageQuality` judges whether a file can fill the ladder;
`Role` maps roles to capabilities in one place. None of these is a bag of
getters with the rules living elsewhere.

**Ports point inward.** `StorageInterface`, `VersionStoreInterface` and
`PublishingStorage` live in the domain; their implementations do not. That is
why versioning could become a decorator, and why SQLite gained history without
a line of history code changing.

**The language is shared and consistent.** A *version* is always a retained
state of a document, *publish* is always the act of promoting one, a *working
copy* is always the newest version. When publication stopped being a field, the
field was removed rather than left as a second vocabulary for the same idea.

### What does not hold

**`Core\Application` is a god object.** 1,558 lines, roughly 58 methods, of which
about 39 concern authentication, sessions, login throttling, CSRF and password
changes. That is an entire bounded context — identity — implemented inside the
HTTP kernel. It should be its own module behind its own interface, leaving the
kernel to turn requests into responses.

**Authorisation is spread across three layers.** Seven permission checks sit in
`Application`, one in `CoreApiRoutes`, five in `PageService`. The domain knows
what a role may do, but nothing structurally requires a handler to ask, so a new
endpoint can simply forget. Checks belong beside the operation they guard, not
at whichever layer noticed first.

**`CoreApiRoutes` is 869 lines** and mixes transport concerns with decisions
that belong in an application service.

These are the same finding twice: the layering is right where it was designed,
and absent where code accumulated.

## Test-driven development

### Where the project actually stands

This has not been a test-driven codebase. Through the work that added languages,
history, preview, media quality, draft-and-publish and the admin UI, tests were
written **after** the implementation they describe. That is test-after
development. It produces a suite — currently 471 PHP tests and 10 component
tests — but it does not produce the design pressure TDD is for, and it did not
catch the defects that mattered.

The evidence is specific. Four defects reached the merged branch:

| Defect | Consequence | Found by |
|---|---|---|
| Page list read a removed `status` field | a fully live site displayed as entirely draft | reading code |
| Dashboard read the same removed field | landing screen reported "0 published" | reading code |
| Slug derived by stripping `page:` | every rendered address became `en/home` | reading code |
| Delete sent no language | **deleted the wrong language's document** | reading code |

Two of those destroy or hide data, and none was caught by a passing suite,
because there was no frontend suite at all.

### What is now in place

`npm test` runs Vitest against the admin components, and the Dockerfile runs it
**before** the build, so a component that misreads the API cannot reach an
image.

### The rule going forward

Write the failing test first. Where that has not happened — a bug found by
reading, a defect reported from elsewhere — the test still comes before the fix,
and **the fix is not accepted until the test has been shown to fail without it.**
Every regression test added in this round was verified that way: the change was
reverted, the test was watched failing, and only then restored.

That last step is not ceremony. The first version of one of these tests asserted
against the whole rendered document and passed even with the bug reintroduced,
because the words it looked for also appeared in a filter tab. A test that has
never been seen to fail is a test that has not been checked.

### What is still untested

- **No frontend tests for `PageEdit.vue`**, which is the largest and most
  stateful component and now carries publication, languages and history.
- **No HTTP-level test harness for `Application`.** Routing, security headers
  and the public-page path are exercised only through container runs by hand.
- **Nothing tests the rendered site's appearance.** Dark mode on the new admin
  screens has been reasoned about from the built CSS, not seen.
