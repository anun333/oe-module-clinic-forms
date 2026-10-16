# Principles

What every document, pack and change in Clinic Forms follows. A change that can't meet one says so in its changelog entry.

1. **Plain language.** Patient documents read at an 8th-grade level or below (Flesch-Kincaid, measured by the tests). Short sentences, everyday words, the patient addressed as "you".
2. **Validated wording is never changed.** A validated instrument (`"validated": true`: PHQ-9, GAD-7, PHQ-2, AUDIT-C) prints its published wording and scores, and its translations are the official ones. If an official translation doesn't exist, the instrument prints in English for an interpreter.
3. **Every document says where it comes from and on what terms** (`source`, `licence`). A copyrighted instrument ships only with its holder's terms recorded and the permission they give (`licence.permission`).
4. **The law's elements are checked, not assumed.** A regulated document is held to its rule's elements in `requirements.json`; the tests fail if a shipped document loses one, and a clinic's own version is warned.
5. **Official government forms are linked or printed from the agency, not recreated** (the ABN, a state's advance directive, POLST).
6. **No site data in shipped files.** Clinic name, address, phone and people come from OpenEMR at print time through placeholders. The only contact details in shipped files are official ones a rule requires (HHS Office for Civil Rights). The tests check.
7. **Core documents apply anywhere.** A document that rests on one country's law or programme belongs in that country's pack (`data/packs/<code>/`). The tests fail if a core document names US law.
8. **A version means one wording.** Any change to what a document says raises its `version`; old versions' wording is recorded (`data/document-versions.json`). The version prints at the foot of each page.
9. **A clinic's own versions are never overwritten.** They live in the site's Clinic Forms folder, replace shipped ones by id, and are told when the module's version they were copied from has changed (`based_on`).
10. **Old wording is never printed for new.** A translation made from older English prints as English, and the page says so, until it is updated.
11. **Nothing is written to OpenEMR's database.** Clinic Forms reads; it prints, downloads and logs an audit event.
12. **Starting points, not legal advice.** Consents and releases are written to be complete and plain; clinics have them reviewed for their state.
