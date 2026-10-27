-- Clinic Forms keeps no tables of its own. This file only adds two indexes on core tables, so that the answers
-- kept on file (0.44.0) are found quickly. Clinic Forms reads a patient's earlier answers from
-- questionnaire_response by patient_id, which core doesn't index, and checks each one against
-- form_questionnaire_assessments.response_id, which core doesn't index either, so every filled print read both
-- tables whole. On a copy with 20,000 patients and 36,209 saved responses that took 24 to 61 ms a print; with
-- both indexes, 0.7 to 2.2 ms, with the same answers.
-- The response_id index is the one the UDS Report, Title X Report, CCBHC Report and Staff Worklist modules add
-- under the same name, so whichever runs first creates it and the others skip it. If core adds either index
-- under these names, it's skipped too.

#IfNotIndex questionnaire_response patient_id
ALTER TABLE `questionnaire_response` ADD INDEX `patient_id` (`patient_id`);
#EndIf

#IfNotIndex form_questionnaire_assessments response_id
ALTER TABLE `form_questionnaire_assessments` ADD INDEX `response_id` (`response_id`(64));
#EndIf
