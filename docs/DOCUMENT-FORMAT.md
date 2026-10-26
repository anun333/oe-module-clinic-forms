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
| `status` | no | `draft` (offered, marked Draft), `active` (the default) or `retired` (no longer offered; packets drop it). A shipped draft is one held back from public release, so it must be listed with its reason in `data/release-gates.json` and in no packet |
| `source` | shipped: yes | `kind`: `written` (ours), `rule` (written to a rule's elements; `url` to the rule; its elements in `requirements.json`) or `instrument` (published wording; `url` to the publisher; validated against LOINC's reference, or its publisher's wording kept in `data/references`). `name` says which. See [CONFORMANCE.md](../CONFORMANCE.md) |
| `licence` | shipped: yes | `holder`, `terms`, and for instruments `permission` (what the holder allows, and on what basis) |
| `based_on` | no | On a clinic's version of a shipped document: the module's version's fingerprint it was copied from. The page shows the value to use |
| `blocks` | yes | What prints, in order |

## Blocks

`heading`, `paragraph`, `fields` (label and optional value), `checklist` (optionally coded: see below; `"names": true` when its items are names of peoples, places or languages, which the reading-level check then leaves out, since it would read their syllables as hard words), `lines` (ruled lines), `signature` (one line per signer, each with a date), `scale` (a question per row and a box per answer, optionally scored), `table` (column headings over blank rows), `taglines` (the language assistance taglines). Exact shapes are in `src/Paper/Document.php`.

## Tables another module fills

A `table` with an `id` can be filled by another module when the document is printed or downloaded (OpenEMR event `clinic_forms.document.fill`; see `src/Event/DocumentFillEvent.php`). The listener gives the rows, and a note on where the figures come from. Unfilled, the table prints blank, as before. Write the label's instruction for filling by hand in parentheses at its end, "Our discounts (the clinic fills in its own schedule)": a filled table drops it. The sliding fee notice's `discounts` table is filled by the Sliding Fee Discount module.

## Skip logic (on screen only)

A question that should only be asked after a certain answer can say so. Printing ignores this, so the paper keeps its written instruction ("If yes, …"). On screen, as a FHIR Questionnaire (`enableWhen`) in OpenEMR or any SDC form filler, the question appears only when its condition holds.

- **`id`** names a block, or a field inside a `fields` block, so a later block can point at it. Use lower-case words joined by hyphens, each used once in the document.
- **`show_if`** goes on the block, or on a single field, that depends on an earlier answer. It holds one condition, or `{"any": [conditions]}` when any one of several will do.
  - **An answer:** `{"id": "acuity", "answer": 1}` means the first answer of the labelled checklist `acuity`. On a scale, give the row as well: `{"id": "screen", "row": 4, "answer": 1}`.
  - **A count above a value:** `{"id": "tobacco", "above": 0}`. The target must be a number field.
- **`number`** on a field makes it a whole number in a range: `"number": [0, 365]`.
- **`item_sex`** on a scale asks a row of one sex only: one entry per row, each `null`, `"male"` or `"female"` (TAPS-1: `[null, "male", "female", null, null]`). On screen it's an SDC `enableWhenExpression` on the patient's gender, exported only for OpenEMR's runtime 0.4.0 or later; a row is still asked when the patient's gender is unknown, which it is wherever the host passes no patient. Like `show_if`, it isn't wording and a translation copies it.

Answers are named by position, so a translation carries exactly the same skip logic as its English document, copied rather than translated. Skip logic isn't wording: adding it doesn't change a document's version. A condition that names something below it, a missing answer, or the wrong kind of target is refused when the file is read. ASQ and BSTAD carry skip logic; see their files for examples.

## Coded questions

A labelled checklist can say what it asks in code, so the answer saved on screen is a code a report can read, whatever the wording. The Title X visit record asks FPAR 2.0's items this way.

- **`code`**: the question's LOINC code, `{"system": "http://loinc.org", "code": "86645-9"}`, check digit verified. On screen it is also the question's linkId (`/86645-9`), so each code once in a document.
- **`answer_codes`**: one code for each item, in the same order. LOINC answers (`LA...`) take LOINC's own text from `data/loinc-names.json`. SNOMED CT answers, and HL7's null flavors (`UNK`), give the code system's own name in `display`.
- **`single`**: `true` when one answer is chosen. Otherwise any number can be ticked.

The paper prints the document's own words. On screen each answer shows its code system's name, as coded instruments do. SNOMED CT codes go out with the US Edition named, which holds the US extension's codes. Codes aren't wording: they change no version, and a translation copies them, like skip logic. A mistake (a wrong check digit, a code for each item missing, a LOINC answer with its own display) is refused when the file is read.

## Placeholders

Text can use `{group.key}`: `facility` (name, address, phone, fax, email, website, npi), `patient` (name, dob, id, address, street, city, state, zip, phone, sex, allergies, medications, problems), `recipient` (an Address Book entry), `provider` (the signing provider: name, credentials, npi, specialty), `user` (the person printing), `clinic.today`. An unknown placeholder is refused when the file is read.

## Translations

A translation sits in a language folder beside its document (`data/documents/es/consent-to-treat.json`) with the same `id` and the same structure: `title`, `category`, `about`, `blocks`, and `translates`, the fingerprint of the English it was made from. Everything else (audience, code, version, status, source, licence) comes from the English document. When the English changes, the translation is out of date and the document prints in English until it is updated. The Spanish files are generated by `dev/clinic_forms_translate_es.py` in the openhealthcare workspace, which fails on any untranslated string.

## A clinic's own versions

- Put the file in the site's Clinic Forms folder; one with a shipped id replaces the shipped document.
- Copy the shipped file to start, and add `"based_on"` with the value the page shows, so the page says when the module's version changes.
- To stop offering a shipped document without replacing it, list its id in `settings.json`: `{"hide": ["financial-policy"]}`.
- Mistakes are reported on the page and the file is left out; the shipped documents stay.
