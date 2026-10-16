<?php

/**
 * The few words Clinic Forms itself prints on a page (labels, hints, the patient line), in the
 * paper's language. English goes through OpenEMR's own translation, as before, so an English
 * page follows the user's language setting; a Spanish page uses this list, whatever language
 * the person printing uses OpenEMR in. A test checks that every word the code prints is here.
 *
 * @package   OpenHealthcare\ClinicForms
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenHealthcare\ClinicForms\Paper;

final class Words
{
    /** The languages a page can be printed in. */
    public const LANGUAGES = ['en' => 'English', 'es' => 'Español'];

    public const ES = [
        'Phone' => 'Teléfono',
        'Fax' => 'Fax',
        'Patient' => 'Paciente',
        'draft' => 'borrador',
        'local version' => 'versión local',
        'DOB' => 'Fecha de nac.',
        'ID' => 'N.º',
        'Patient name' => 'Nombre del paciente',
        'Date of birth' => 'Fecha de nacimiento',
        'Patient ID' => 'Número de paciente',
        'Date' => 'Fecha',
        'please check and correct' => 'revise y corrija, por favor',
        'This is what we have on file. Cross out anything that is wrong and write the correction beside it, and fill in anything missing.'
            => 'Esta es la información que tenemos. Tache lo que esté mal, escriba la corrección al lado y complete lo que falte.',
        'For office use' => 'Para uso de la oficina',
        'N/A' => 'No aplica',
        'Notes' => 'Notas',
        'tick one' => 'marque una',
        'tick all that apply' => 'marque todas las que correspondan',
        'Other' => 'Otro',
        'Current' => 'Actualmente',
        'Quit' => 'Lo dejó',
        'Never' => 'Nunca',
        'Exam or Test' => 'Examen o prueba',
        'Normal' => 'Normal',
        'Abnormal' => 'Anormal',
        'Date/Notes' => 'Fecha y notas',
        'Language assistance' => 'Asistencia con el idioma',
    ];

    public static function t(string $english, string $language): string
    {
        return $language === 'es' ? (self::ES[$english] ?? $english) : xl($english);
    }

    public static function language(mixed $requested): string
    {
        return is_string($requested) && isset(self::LANGUAGES[$requested]) ? $requested : 'en';
    }
}
