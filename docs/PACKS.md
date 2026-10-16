# Country packs

A country pack holds what one country's law and programmes add to the core. Clinic Forms ships one, the United States (`data/packs/us/`). The authoritative description is the comment at the top of `src/Paper/Pack.php`.

```
data/packs/<code>/pack.json            name, jurisdiction, the facility country names that choose it, paper, layout forms
data/packs/<code>/documents/           documents; translations in documents/<language>/
data/packs/<code>/requirements.json    the legal elements its documents are held to (format of data/requirements.json)
data/packs/<code>/packets.json         packets; one with a core packet's id replaces it
data/packs/<code>/taglines.json        language assistance taglines, if the country's law has them
data/packs/<code>/regions.json         regions (states, provinces) and what they change
data/packs/<code>/regions/<region>/documents/   a region's own documents, replacing the pack's by id
```

`pack.json`:

```json
{
  "about": "...",
  "name": "United States",
  "jurisdiction": "US",
  "countries": ["US", "USA", "United States"],
  "paper": "letter",
  "layouts": ["UDS"]
}
```

- **The folder name** is the ISO 3166-1 code in lower case, and `jurisdiction` is the same code in capitals. A region's ISO 3166-2 code is `<jurisdiction>-<region>` (US-TX).
- **`countries`** are the spellings of the facility's Country (Administration › Facilities) that choose the pack. A clinic can choose in `settings.json` instead: `{"country": "us"}` or `{"country": "none"}`.
- **`paper`** is the size the picker starts on: `letter` or `a4`.
- **`layouts`** are the extra layout forms the pack offers (the US pack's short UDS form).

**Order.** Core, then the pack, then the region (from the facility's State), then the clinic's own folder; each replaces the one before by id.

**Before writing a pack:**
1. Read the country's law in full (not summaries) and list each required element in `requirements.json` with its citation, as the US pack does for HIPAA, 42 CFR Part 2 and Section 1557.
2. Put only what rests on that law in the pack; check that the core documents read correctly there.
3. Use the country's official translations of validated instruments; where none exist, the instrument stays in English.
4. Record `version`, `source` and `licence` on every document and run `php tools/document-versions.php`.
5. Add the pack to the end-to-end check: chosen by setting, by facility country, and its regions.
