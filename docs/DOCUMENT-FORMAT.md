# Document format

A document is one JSON file named by its id (`consent-to-treat.json`), in `data/documents/` (core), `data/packs/<code>/documents/` (a country pack), or the site's Clinic Forms folder (`sites/<site>/documents/clinic-forms/`, a clinic's own). The authoritative description is the comment at the top of `src/Paper/Document.php`; this page is the guide.

```json
{
  "id": "consent-to-treat",
  "title": "Consent to Treatment",
  "category": "Front desk",
  "about": "General consent for examination and treatment",
  "audience": "patient",
  "code": {"system": "http://loinc.org", "code": "59284-0", "display": "Consent Document"},
  "version": 1,
  "status": "active",
  "source": {"kind": "written", "name": "Written for Clinic Forms"},
  "licence": {"holder": "Clinic Forms contributors", "terms": "GPL-3.0-or-later, as the module. Adapt it for your clinic."},
  "blocks": [
    {"type": "fields", "fields": [{"label": "Patient name", "value": "{patient.name}"}]},
    {"type": "paragraph", "text": "I agree to be examined and treated by {facility.name}."},
    {"type": "signature", "signers": ["Patient or legal representative"]}
  ]
}
```

## Fields

| Field | Required | Meaning |
|---|---|---|
| `id` | yes | The file's name: lower-case words joined by hyphens. Translations and a clinic's own versions use the same id |
| `title`, `category`, `about` | yes | As shown in the picker; `about` is for staff |
| `audience` | yes | `patient`, `staff` or `provider`. Patient documents are measured for reading level and get the language taglines |
| `code` | no | A LOINC document code, check digit verified. Leave it out rather than use a near miss |
| `validated` | no | `true` for a validated instrument: wording and scores are the published ones |
| `poster` | no | `true` for a notice to post: 20-point type, no patient line |
| `version` | shipped: yes | A whole number from 1. Raise it whenever the wording changes; it prints as `id vN` at the foot of each page and goes in the audit event |
| `status` | no | `draft` (offered, marked Draft), `active` (the default) or `retired` (no longer offered; packets drop it) |
| `source` | shipped: yes | `kind`: `written` (ours), `rule` (written to a rule's elements; `url` to the rule) or `instrument` (published wording; `url` to the publisher). `name` says which |
| `licence` | shipped: yes | `holder`, `terms`, and for instruments `permission` (what the holder allows, and on what basis) |
| `based_on` | no | On a clinic's version of a shipped document: the module's version's fingerprint it was copied from. The page shows the value to use |
| `blocks` | yes | What prints, in order |

## Blocks

`heading`, `paragraph`, `fields` (label and optional value), `checklist`, `lines` (ruled lines), `signature` (one line per signer, each with a date), `scale` (a question per row and a box per answer, optionally scored), `table` (column headings over blank rows), `taglines` (the language assistance taglines). Exact shapes are in `src/Paper/Document.php`.

## Skip logic (on screen only)

A question that should only be asked after a certain answer can say so. Printing ignores this, so the paper keeps its written instruction ("If yes, …"). On screen, as a FHIR Questionnaire (`enableWhen`) in OpenEMR or any SDC form filler, the question appears only when its condition holds.

- **`id`** names a block, or a field inside a `fields` block, so a later block can point at it. Use lower-case words joined by hyphens, each used once in the document.
- **`show_if`** goes on the block, or on a single field, that depends on an earlier answer. It holds one condition, or `{"any": [conditions]}` when any one of several will do.
  - **An answer:** `{"id": "acuity", "answer": 1}` means the first answer of the labelled checklist `acuity`. On a scale, give the row as well: `{"id": "screen", "row": 4, "answer": 1}`.
  - **A count above a value:** `{"id": "tobacco", "above": 0}`. The target must be a number field.
- **`number`** on a field makes it a whole number in a range: `"number": [0, 365]`.

Answers are named by position, so a translation carries exactly the same skip logic as its English document, copied rather than translated. Skip logic isn't wording: adding it doesn't change a document's version. A condition that names something below it, a missing answer, or the wrong kind of target is refused when the file is read. ASQ and BSTAD carry skip logic; see their files for examples.

## Placeholders

Text can use `{group.key}`: `facility` (name, address, phone, fax, email, website, npi), `patient` (name, dob, id, address, street, city, state, zip, phone, sex, allergies, medications, problems), `recipient` (an Address Book entry), `provider` (the signing provider: name, credentials, npi, specialty), `user` (the person printing), `clinic.today`. An unknown placeholder is refused when the file is read.

## Translations

A translation sits in a language folder beside its document (`data/documents/es/consent-to-treat.json`) with the same `id` and the same structure: `title`, `category`, `about`, `blocks`, and `translates`, the fingerprint of the English it was made from. Everything else (audience, code, version, status, source, licence) comes from the English document. When the English changes, the translation is out of date and the document prints in English until it is updated. The Spanish files are generated by `dev/clinic_forms_translate_es.py` in the openhealthcare workspace, which fails on any untranslated string.

## A clinic's own versions

- Put the file in the site's Clinic Forms folder; one with a shipped id replaces the shipped document.
- Copy the shipped file to start, and add `"based_on"` with the value the page shows, so the page says when the module's version changes.
- To stop offering a shipped document without replacing it, list its id in `settings.json`: `{"hide": ["financial-policy"]}`.
- Mistakes are reported on the page and the file is left out; the shipped documents stay.
