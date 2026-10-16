# Clinic Forms for OpenEMR

Printable clinic paperwork, with the clinic's own name, address, phone, fax and logo filled in. Each piece can be printed blank to fill in by hand, or with the current patient's details filled in.

**Status: 0.1.0, a pre-release.** Checked on OpenEMR 8.1.0 to 8.5.0, as an administrator, with synthetic data (see [Compatibility](#compatibility)). The consents and releases are starting points: have them reviewed for your state before you use them (see [The documents are starting points](#the-documents-are-starting-points)).

It does three things:

- **Paperwork.** Consents, releases, referral letters and office slips, filled from the facility record and, optionally, the open patient and an Address Book entry.
- **Paper versions of layout forms.** Demographics, History, any active encounter form (LBF) and any transaction form (Referral and the like), printed as a blank form with tick boxes, date boxes and ruled lines. Clinics without a computer in every room can do intake on paper and type it in later, in the same order.
- **Ready-made packets.** New patient intake, annual update, telehealth, referral and a downtime kit, each opened as one document to print.

It stores nothing and changes nothing in the database. Every page is built when it's opened, from the site's own facility, Address Book and layout settings.

## Using it

**Miscellaneous › Clinic Forms**, just below Blank Forms.

1. Pick the letterhead (any active facility; the primary business entity comes first), the paper size, and optionally a recipient from the Address Book.
2. If a patient is open, tick **Fill in the current patient** to put their name, date of birth, chart ID, address and phone on the pages. Otherwise everything prints blank.
3. Tick documents and forms, or click a ready-made packet, then **Open for printing**. The packet opens in a new tab with a **Print** button.

A packet prints in its own order; anything ticked by hand follows, in the order of the page.

## The documents

| Category | Document | What it is |
|---|---|---|
| Front desk | Consent to Treatment | General consent for examination and treatment |
| Front desk | Acknowledgment of the Notice of Privacy Practices | Receipt of the Notice, with a clinic-use section for when it wasn't obtained |
| Front desk | Financial Policy and Assignment of Benefits | Payment responsibilities, insurance billing and assignment of benefits |
| Front desk | Authorization to Release Health Information | A HIPAA authorization with the elements 45 CFR 164.508 requires, separate initials for sensitive records, and a 42 CFR Part 2 note |
| Front desk | Communication Preferences | How the clinic may contact the patient: phone, voicemail, text, email, portal |
| Front desk | Consent for Telehealth Visits | Consent to visits by video or telephone |
| Care coordination | Referral letter | To the Address Book recipient, with urgency, the question to answer and what is enclosed |
| Care coordination | Request for Records | A request to another provider for a patient's records |
| Office workflow | Patient Routing Slip | Travels with the patient: check-in, rooming, provider, checkout |
| Office workflow | Staff Handoff (SBAR) | Handing a patient from one staff member, shift or site to another |
| Office workflow | Internal Referral | To behavioral health, care management, nutrition, social work or another of the clinic's sites |
| Downtime | Downtime Visit Note | A paper visit note for when OpenEMR is down, with a box to record when it was entered |

Each is a JSON file in `data/documents/`, the same on every OpenEMR release.

### The documents are starting points

They are written to be plain and complete, not as legal advice. Consent, release and financial wording varies by state and by payer, and some states have their own required forms (minors' consent, mental health, HIV testing). Read them with whoever reviews your clinic's forms before using them, and replace any of them with your own (see the next section).

### Your own documents

Put your own documents in `sites/<site>/documents/clinic-forms/` (for most installs, `sites/default/documents/clinic-forms/`), in the same format as `data/documents/`. That folder belongs to the site, so module updates leave it alone.

- A file with a new id adds a document. A file with the id of a shipped document replaces it, so the clinic's edited consent prints instead of the module's.
- Files are checked strictly. A document with an unknown field, an unknown block type or a placeholder that doesn't exist is left out, and the Clinic Forms page names the file and the mistake.

The format is in `src/Paper/Document.php`. Blocks are headings, paragraphs, fields, checklists, ruled lines and signature lines. Text can use these placeholders, which print as a blank line when the value isn't known:

| Group | Placeholders |
|---|---|
| `facility` | `{facility.name}` `{facility.address}` `{facility.phone}` `{facility.fax}` `{facility.email}` `{facility.website}` `{facility.npi}` |
| `patient` | `{patient.name}` `{patient.dob}` `{patient.id}` `{patient.address}` `{patient.phone}` |
| `recipient` | `{recipient.name}` `{recipient.organization}` `{recipient.specialty}` `{recipient.address}` `{recipient.phone}` `{recipient.fax}` `{recipient.email}` |
| `clinic` | `{clinic.today}` |

## Paper versions of layout forms

Each group of the layout becomes a heading, and each field in use a labelled blank shaped for its type:

- lists, checkboxes and radio buttons print their options as tick boxes, marked "tick one" or "tick all that apply" (lists of more than 24 options print as a line);
- exam results, textbox lists and checkboxes with text print a row per option, as OpenEMR's own blank forms do;
- lifestyle fields print Current / Quit (with a date) / Never / N/A;
- dates print as day, month and year boxes, text areas as ruled lines, signatures as a signature line;
- fields that pick a record (a provider, pharmacy, facility, code or insurance) print as a line.

Short answers sit two to a row. Labels and list options follow the site's translation settings, as the screens do. A field with no label on screen (middle and last name share the "Name" row) is labelled with its description.

On Demographics, the fields the clinic fills rather than the patient (chart number, providers, portal login, registry and publicity codes, deceased date and others: the list is `LayoutPaper::OFFICE_FIELDS`) print last, in a **For office use** box.

The form prints whatever the site's layout has in use, so a long layout makes a long form. To shorten it, hide fields under **Administration › Forms › Layouts**.

## Printing

Printing is the browser's own: no print server and nothing to install. When **Administration › Config › Logging › Printing Log Option** is on, the Print button goes through OpenEMR's print logging, like the rest of OpenEMR.

## Install

```
composer require anun333/oe-module-clinic-forms
```

Run it in the OpenEMR directory. It installs into `interface/modules/custom_modules/oe-module-clinic-forms/`. Then, under **Modules › Manage Modules**, register, install and enable **Clinic Forms**. There is no install SQL.

## Access

As written in the code, not verified for restricted users:

- The menu entry and both pages need **Patients › Demographics** (`patients/demo`), view.
- The print page accepts only a form posted from the Clinic Forms page (CSRF token).
- Printing with a patient's details filled in writes a `patient-record-select` event to OpenEMR's audit log, naming the patient and the documents. Blank printing writes nothing.

[FOOTPRINT.md](FOOTPRINT.md), generated from the source, lists everything it reads, the OpenEMR functions it calls and what it hooks.

## Compatibility

| OpenEMR | Checked | How |
|---|---|---|
| 8.4.1 | 2026-10-15 | Installed through Module Manager. The picker and a packet of every document, Demographics, History and Referral printed with no PHP errors, blank and filled, and printed to PDF in Chromium to check the pages. A print without the CSRF token is refused. Local documents, valid and broken, tested. |
| 8.1.0, 8.2.0, 8.3.0, 8.4.0, 8.5.0 | 2026-10-15 | Official images, fresh install: installed through Module Manager; the picker loads and a blank packet of all 12 documents, Demographics, History and Referral prints all 15 pages with no PHP error. |

It needs PHP 8.3 or later. OpenEMR 8.0.0 and older haven't been checked.

## Development

```
composer install
vendor/bin/phpunit
php tools/footprint.php --check
```

The tests need no database: they cover the document library (every shipped document loads and renders blank and filled with no placeholder left), the escaping, the packets and the paper version of each field type.

## Known limitations

- Pages after the first don't repeat the patient's name, so keep a multi-page form together (or write the name on each page).
- Lists of more than 24 options (language, country) print as a line to write in.
- Layout forms print blank. Filling Demographics with what's on file, for a returning patient to check and correct, isn't built yet.
- Documents are in English. Their text doesn't go through OpenEMR's translation yet.
- Adding your own documents means writing JSON files on the server, and packets can't be added yet.
- Printing only: nothing is faxed, emailed or saved to the patient's Documents, and nothing is scanned back.
- Questionnaires and Patient Portal document templates don't print from here.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
