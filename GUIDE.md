# Clinic Forms: staff guide

For the staff who use the module day to day, task by task. What each document is, where its wording comes from, and how to install the module, are in the [README](README.md).

Clinic Forms prints and downloads paperwork: consents, releases, letters, intake forms, screening questionnaires and blank paper versions of your layout forms. The consents and releases are starting points: have them reviewed for your state before use. It has been tested only on made-up patients.

Everything starts on one page: the Miscellaneous menu › **Clinic Forms**. Tick the documents you want, set the options at the top, and choose what to do with them at the bottom.

## Front desk

### Print a new patient's intake packet

1. Open the patient first if the forms should be filled in for them.
2. Under **Ready-made**, choose the packet, for example new patient intake. It ticks the packet's documents; **Clear** unticks everything.
3. Choose the **Letterhead** (the facility) and the **Language**. Documents marked ES print in Spanish; the rest stay in English.
4. Tick **Fill in the current patient** to print the patient's details on each page. Tick **Also fill Demographics, History and the UDS form with what is on file, for the patient to check and correct** for a returning patient; Social Security and driver license numbers print as their last four characters.
5. Choose **Open for printing**, then print from the browser.

Leave **Add the language assistance taglines to patient pages** ticked: Section 1557 asks for the notices on intake, consent and privacy forms.

### Give a patient who uses a screen reader their forms

Tick the documents and choose **Accessible copy**. On a server with LibreOffice it is a PDF tagged for screen readers; otherwise it is the Word file, which a screen reader can read. The page says which applies on your server.

### Keep a copy in the chart

With the patient open, choose **Save a PDF copy in the patient chart**. The ticked documents are filed, filled for that patient, under Documents.

## Clinical staff

### Print a screening questionnaire

Tick the questionnaire (PHQ-9, GAD-7, AUDIT-C and the others) and choose **Open for printing**, filled for the patient or blank. A questionnaire your clinic has changed shows **local version**, with a warning when it **differs from the published instrument, so its score may not mean what the published cut-offs say**.

### Enter a paper questionnaire's answers in OpenEMR

An administrator first adds the questionnaire to OpenEMR (below). Then, in an encounter, open Questionnaires › New Questionnaire, or on OpenEMR 8.3 and later the patient menu › Assessments › Questionnaires, and enter the answers. OpenEMR adds up the score and keeps the answers in the chart.

The score appears only once every scored question is answered. If the patient left one blank, the total stays empty rather than showing a lower number, so ask about the missing question, or note that the score is incomplete. A question that depends on an earlier answer counts only when it's asked: AUDIT-C answered "Never", or PC-PTSD-5 answered "No" to the trauma question, scores 0 with nothing more, as the forms say, but a question the screen does ask must be answered. On TAPS-2, a substance answered No and skipped shows no part score rather than 0. Part scores, such as PHQ-4's two pairs, wait only for their own questions.

## Providers

### Write a letter or referral

1. Tick the letter, for example a referral letter or a release.
2. Choose the **Recipient (for referral letters and releases)** from the Address Book, and the **Signing provider (for letters and notes)**: their name, credentials and NPI come from Administration › Users.
3. With the patient open, tick **Fill in the current patient**.
4. Choose **Open for printing**, or download it to edit first: **Word** or **LibreOffice**.

## The administrator or office manager

### Add questionnaires to OpenEMR

1. Tick the questionnaires.
2. Optionally, under **Also list them in visits of these types (optional)**, tick visit types, so each appears in those visits' Questionnaires menu (OpenEMR 8.5 and later).
3. Choose **Add to OpenEMR questionnaires**. Adding a form again updates it; a questionnaire Clinic Forms didn't add is left alone.

To use the forms in another system, **FHIR Questionnaire** downloads them as blank forms, never with patient details. Each is the complete form, ready to fill, including those built from shared parts, such as the PHQ-9 with the PHQ-2's questions.

### Add your clinic's own Word or LibreOffice documents

*Where:* the bottom of the page › **Add your own Word or LibreOffice documents**.

Write the letter or form in Word or LibreOffice with placeholders in braces where details should go (the page lists them), and choose **Add**. It then appears under **Your Word and LibreOffice documents**, and staff download it filled in. **Remove** takes it off.

### Check the Spanish versions

Spanish made with machine help is marked **ES needs review**: have a qualified translator review it before it goes to patients, as federal rules ask (45 CFR 92.201). **ES out of date** means the English changed since it was translated; it prints in English until updated.

### Fix local documents the page flags

When **Some local documents or packets need attention** shows, the page lists what to fix, such as a local copy without the version it was copied from, or a shared block of your own (a part your documents include by name, such as a signature section) that has a mistake or takes the name of one of the module's. Your own versions and settings live in your site's Clinic Forms folder; the README explains them.
