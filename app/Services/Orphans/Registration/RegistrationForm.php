<?php

namespace App\Services\Orphans\Registration;

use App\Services\Orphans\ArabicText;
use App\Services\Orphans\Identity;
use App\Services\Orphans\Persons;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Data contract of the family registration form (see docs/aims2/AIMS2_REGISTRATION_DATA_DICTIONARY_AR.md).
 *
 * Every rule is a type rule; a draft accepts any partial answer. Requirements that depend on the answers (death
 * details, missing-person details, household totals, guardian per child, declarations) apply only on submission
 * through issues(). Missing answers stay missing: nothing is inferred.
 */
final class RegistrationForm
{
    public const YNU = ['yes', 'no', 'unknown'];

    public const YNU_LABELS = ['yes' => 'نعم', 'no' => 'لا', 'unknown' => 'غير معروف'];

    public const GUARDIAN_ROLES = ['applicant', 'mother', 'other'];

    /** Income per source: «known» needs an amount; «none» is a declared nothing; «unknown» is not asked or not given. */
    public const AMOUNT_STATUS = ['known' => 'مبلغ معروف', 'none' => 'لا يوجد', 'unknown' => 'غير معروف'];

    public const CONTACT_CHANNELS = ['phone' => 'اتصال هاتفي', 'sms' => 'رسالة نصية', 'whatsapp' => 'واتساب', 'in_person' => 'حضوري', 'other' => 'أخرى'];

    /** Mother education levels that make a field of study relevant. */
    public const HIGHER_EDUCATION = ['diploma', 'bachelor', 'master', 'doctorate', 'postgraduate'];

    /** Why an identity number is absent: a documented reason, never a placeholder number. */
    public const ID_MISSING_REASONS = ['not_issued' => 'لم تصدر له هوية بعد', 'lost' => 'الوثيقة مفقودة', 'not_available' => 'غير متوفرة حاليًا', 'unknown' => 'الرقم غير معروف للمُفيد', 'other' => 'سبب آخر'];

    public const CAREGIVING = ['yes' => 'نعم', 'partial' => 'جزئيًا', 'no' => 'لا', 'unknown' => 'غير معروف'];

    public const SHELTER_STABILITY = ['stable' => 'مستقر', 'temporary' => 'مؤقت', 'at_risk' => 'مهدد بالإخلاء', 'unknown' => 'غير معروف'];

    public const INFO_SOURCES = ['self_reported' => 'إفادة الأسرة', 'document' => 'مستند', 'field_visit' => 'زيارة ميدانية', 'other' => 'أخرى'];

    public const MAX_CHILDREN = 15;

    public const MAX_MEMBERS = 30;

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        $master = ['nullable', 'integer', Rule::exists('master_data_values', 'id')];
        $ynu = ['nullable', Rule::in(self::YNU)];
        $money = ['nullable', 'numeric', 'min:0', 'max:9999999'];
        $currency = ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')];
        $date = ['nullable', 'date', 'before_or_equal:today'];
        $text = fn (int $max): array => ['nullable', 'string', 'max:'.$max];
        $count = ['nullable', 'integer', 'min:0', 'max:200'];

        $rules = [
            'request' => ['nullable', 'array'], 'request.relationship_value_id' => $master, 'request.notes' => $text(2000),
            ...self::person('request.applicant.', true, true), 'request.applicant.contact_channel' => ['nullable', Rule::in(array_keys(self::CONTACT_CHANNELS))],
            'declaration' => ['nullable', 'array'], 'declaration.truth' => ['nullable', 'boolean'], 'declaration.consent' => ['nullable', 'boolean'], 'declaration.name' => $text(200),
        ];

        foreach (['father', 'mother'] as $parent) {
            $p = $parent.'.';
            $rules += [
                $parent => ['nullable', 'array'], ...self::person($p),
                $p.'vital_status' => ['nullable', Rule::in(array_keys(Persons::VITAL_LABELS))], $p.'status_source' => $text(200),
                $p.'death.date' => $date, $p.'death.date_unknown' => ['nullable', 'boolean'], $p.'death.place' => $text(200), $p.'death.cause_value_id' => $master,
                $p.'death.cause_note' => $text(500), $p.'death.certificate_no' => $text(60), $p.'death.evidence_note' => $text(500), $p.'death.official_cause' => $text(500),
                $p.'status_confidence' => ['nullable', Rule::in(['high', 'medium', 'low'])], $p.'missing.details' => $text(1000),
                $p.'missing.last_seen_on' => $date, $p.'missing.date_unknown' => ['nullable', 'boolean'], $p.'missing.last_seen_place' => $text(200),
                $p.'missing.reported_on' => $date, $p.'missing.reported_to' => $text(200), $p.'missing.report_ref' => $text(100),
                $p.'education.level_value_id' => $master, $p.'education.specialization' => $text(150), $p.'education.institution' => $text(200), $p.'education.vocational' => $text(300),
                $p.'economic.occupation' => $text(200), $p.'economic.employment_status_value_id' => $master, $p.'economic.employed' => $ynu, $p.'economic.employer' => $text(200),
                $p.'economic.stability' => ['nullable', Rule::in(['stable', 'irregular', 'seasonal', 'unknown'])], $p.'economic.income_amount' => $money, $p.'economic.currency' => $currency,
                $p.'economic.income_regularity' => ['nullable', Rule::in(['regular', 'irregular', 'unknown'])], $p.'economic.other_income' => $text(300),
                $p.'economic.dependents_count' => $count, $p.'economic.notes' => $text(2000), $p.'economic.source' => ['nullable', Rule::in(['self_reported', 'caregiver', 'document', 'field_visit'])],
                $p.'economic.confidence' => ['nullable', Rule::in(['high', 'medium', 'low'])], $p.'economic.sector' => $text(150), $p.'economic.income_band_value_id' => $master,
                $p.'economic.benefits' => $ynu, $p.'economic.benefits_note' => $text(500), $p.'death.documents' => $ynu,
            ];
        }
        $rules += [
            'father.residence_before' => $text(500), 'father.economic.supported_household' => $ynu, 'father.economic.breadwinner_loss' => $ynu,
            'mother.marital_status_value_id' => $master, 'mother.marital_status_since' => $date, 'mother.contact_channel' => ['nullable', Rule::in(array_keys(self::CONTACT_CHANNELS))], 'mother.resides_with_household' => $ynu,
            ...self::address('mother.address.'),
            'mother.care.support_needs' => $text(1000), 'mother.care.accommodation' => $text(1000),
            'mother.caregiving.capacity' => ['nullable', Rule::in(array_keys(self::CAREGIVING))], 'mother.caregiving.note' => $text(500),
        ];

        $rules += [
            'guardians' => ['nullable', 'array', 'max:'.self::MAX_CHILDREN], 'guardians.*.key' => ['required', 'string', 'max:36', 'distinct'],
            'guardians.*.role' => ['nullable', Rule::in(self::GUARDIAN_ROLES)], ...self::person('guardians.*.', true, true),
            'guardians.*.contact_channel' => ['nullable', Rule::in(array_keys(self::CONTACT_CHANNELS))], 'guardians.*.resides_with_household' => $ynu, 'guardians.*.displaced' => $ynu,
            ...self::address('guardians.*.address.'),
            'guardians.*.education.level_value_id' => $master, 'guardians.*.education.specialization' => $text(150),
            'guardians.*.economic.employed' => $ynu, 'guardians.*.economic.occupation' => $text(200), 'guardians.*.economic.stability' => ['nullable', Rule::in(['stable', 'irregular', 'seasonal', 'unknown'])],
            'guardians.*.economic.income_amount' => $money, 'guardians.*.economic.currency' => $currency, 'guardians.*.economic.other_income' => $text(300),
            'guardians.*.economic.dependents_count' => $count, 'guardians.*.economic.housing_note' => $text(300), 'guardians.*.economic.notes' => $text(2000),
        ];

        $h = 'household.';
        $rules += [
            'household' => ['nullable', 'array'], $h.'display_name' => $text(200),
            $h.'declared_members' => $count, $h.'male_count' => $count, $h.'female_count' => $count, $h.'unknown_sex_count' => $count, $h.'studying_count' => $count,
            $h.'employed_count' => $count, $h.'dependents_count' => $count, $h.'earners_count' => $count, $h.'assessed_on' => $date, $h.'count_exception_reason' => $text(1000),
            $h.'special_needs_count' => $count, $h.'head_relation_value_id' => $master, $h.'contact_phone' => ['nullable', 'string', 'max:20'],
            $h.'rent_amount' => $money, $h.'employment_stability' => ['nullable', Rule::in(['stable', 'irregular', 'seasonal', 'unknown'])], $h.'assessed_economic_status_value_id' => $master,
            $h.'income_source_ids' => ['nullable', 'array', 'max:10'], $h.'income_source_ids.*' => $master, $h.'total_income' => $money, $h.'currency' => $currency,
            $h.'income_period' => ['nullable', Rule::in(['monthly', 'weekly', 'yearly'])], $h.'income_note' => $text(500), $h.'economic_status_value_id' => $master,
            $h.'expenses_amount' => $money, $h.'unmet_needs' => $text(1000), $h.'external_aid' => $ynu,
            $h.'aid' => ['nullable', 'array', 'max:10'], $h.'aid.*.organization' => $text(200), $h.'aid.*.support_type_value_id' => $master, $h.'aid.*.support_type_note' => $text(200),
            $h.'aid.*.frequency_value_id' => $master, $h.'aid.*.regularity' => ['nullable', Rule::in(['regular', 'irregular', 'one_off', 'unknown'])], $h.'aid.*.received_on' => $date,
            $h.'aid.*.child_key' => ['nullable', 'string', 'max:36'], $h.'aid.*.amount' => $money, $h.'aid.*.currency' => $currency, $h.'aid.*.quantity' => $text(100), $h.'aid.*.note' => $text(500),
            $h.'tenure_value_id' => $master, $h.'utilities_access' => $text(300), $h.'living_conditions' => $text(1000),
            $h.'members' => ['nullable', 'array', 'max:'.self::MAX_MEMBERS], ...self::person($h.'members.*.', false), $h.'members.*.relation_value_id' => $master,
            $h.'members.*.is_studying' => $ynu, $h.'members.*.is_employed' => $ynu, $h.'members.*.is_dependent' => $ynu, $h.'members.*.since' => $date,
            // Old file number (reference only; the old system repeats it) and income per source with an explicit
            // amount status: a missing amount is «غير معروف», never zero (legacy report R9).
            $h.'legacy_file_no' => $text(40), $h.'contact_unreachable_reason' => $text(300),
            $h.'income_items' => ['nullable', 'array', 'max:15'], $h.'income_items.*.source_value_id' => $master, $h.'income_items.*.label' => $text(200),
            $h.'income_items.*.amount_status' => ['nullable', Rule::in(array_keys(self::AMOUNT_STATUS))],
            $h.'income_items.*.amount' => [...$money, 'required_if:'.$h.'income_items.*.amount_status,known'], $h.'income_items.*.currency' => $currency,
            $h.'income_items.*.period' => ['nullable', Rule::in(['monthly', 'weekly', 'yearly', 'one_off'])], $h.'income_items.*.note' => $text(500),
        ];

        $rules += [
            'residence' => ['nullable', 'array'], ...self::address('residence.original.'), ...self::address('residence.current.'),
            'residence.current.housing_condition' => $text(200), 'residence.location_missing_reason' => $text(300),
            'residence.original.housing_type_value_id' => $master, 'residence.original.building_condition_value_id' => $master, 'residence.current.dwelling_condition_value_id' => $master,
            'residence.current.rooms_count' => ['nullable', 'integer', 'min:0', 'max:100'], 'residence.current.building_area_sqm' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'residence.current.building_type_value_id' => $master, 'residence.current.building_condition_value_id' => $master, 'residence.current.furnishing_condition_value_id' => $master,
            'residence.displaced' => $ynu, 'residence.displaced_since' => $date,
            'residence.displacement_count' => ['nullable', 'integer', 'min:0', 'max:100'], 'residence.shelter_type_value_id' => $master, 'residence.contact_accessibility' => $text(200),
            'residence.last_moved_on' => $date, 'residence.current.stability' => ['nullable', Rule::in(array_keys(self::SHELTER_STABILITY))],
            'residence.hosted' => $ynu, 'residence.host_note' => $text(300), 'residence.accessibility_needs' => $text(500), 'residence.source' => ['nullable', Rule::in(array_keys(self::INFO_SOURCES))],
            'residence.history' => ['nullable', 'array', 'max:20'], ...self::address('residence.history.*.'), 'residence.history.*.moved_on' => $date,
            'residence.history.*.left_on' => $date, 'residence.history.*.reason' => $text(300),
        ];

        $c = 'children.*.';
        $rules += [
            'children' => ['nullable', 'array', 'max:'.self::MAX_CHILDREN], $c.'key' => ['required', 'string', 'max:36', 'distinct'], ...self::person($c),
            $c.'civil_registry_ref' => $text(60), $c.'guardian_key' => ['nullable', 'string', 'max:36'], $c.'kinship_value_id' => $master, $c.'resides_with_household' => ['nullable', 'boolean'],
            $c.'kinship_note' => $text(200), $c.'guardianship.court_status_value_id' => $master,
            $c.'education.grade_value_id' => $master, $c.'education.achievement_value_id' => $master,
            $c.'guardianship.authority_kind_value_id' => $master, $c.'guardianship.issuing_authority' => $text(200), $c.'guardianship.decision_no' => $text(100),
            $c.'guardianship.starts_on' => ['nullable', 'date'], $c.'guardianship.ends_on' => ['nullable', 'date'], $c.'guardianship.evidence_note' => $text(1000),
            $c.'education.status_value_id' => $master, $c.'education.stage_value_id' => $master, $c.'education.grade' => $text(60), $c.'education.school_name' => $text(200),
            $c.'education.institution_type_value_id' => $master, $c.'education.modality' => ['nullable', Rule::in(['in_person', 'remote', 'blended', 'other'])],
            $c.'education.academic_year' => ['nullable', 'string', 'regex:/^\d{4}\/\d{4}$/'], $c.'education.attendance' => $text(300), $c.'education.performance' => $text(100),
            $c.'education.nonattendance_reason' => $text(300), $c.'education.barriers' => $text(1000), $c.'education.transport_need' => ['nullable', 'boolean'],
            $c.'education.assistive_need' => $text(300), $c.'education.specialization' => $text(150),
            $c.'health.status_value_id' => $master, $c.'health.details' => $text(2000), $c.'health.disability_type_value_id' => $master,
            $c.'health.severity' => ['nullable', Rule::in(['mild', 'moderate', 'severe', 'unknown'])], $c.'health.regular_follow_up' => $ynu, $c.'health.follow_up_frequency' => $text(100),
            $c.'health.medications' => $text(500), $c.'health.assistive_devices' => $text(300), $c.'health.access_needs' => $text(500), $c.'health.accommodations' => $text(1000),
            $c.'health.source' => ['nullable', Rule::in(['caregiver', 'clinician', 'document'])], $c.'health.assessed_on' => $date,
            $c.'social.loss_circumstances' => $text(1000), $c.'social.researcher_notes' => $text(2000),
            $c.'needs.food' => $ynu, $c.'needs.clothing' => $ynu, $c.'needs.education' => $ynu, $c.'needs.health' => $ynu, $c.'needs.displacement_impact' => $text(500), $c.'needs.notes' => $text(1000),
            $c.'sponsorship.status' => $ynu, $c.'sponsorship.organization' => $text(200), $c.'sponsorship.support_type_value_id' => $master,
            $c.'sponsorship.regularity' => ['nullable', Rule::in(['regular', 'irregular', 'one_off', 'unknown'])], $c.'sponsorship.frequency_value_id' => $master,
            $c.'sponsorship.starts_on' => ['nullable', 'date'], $c.'sponsorship.ends_on' => ['nullable', 'date'], $c.'sponsorship.amount' => $money, $c.'sponsorship.currency' => $currency,
            $c.'sponsorship.evidence_note' => $text(500),
        ];

        return $rules;
    }

    /** Person fields shared by every person entry; validation never makes them required (see issues()). */
    private static function person(string $prefix, bool $contact = true, bool $email = false): array
    {
        $rules = Persons::rules($prefix, false);
        unset($rules[$prefix.'notes'], $rules[$prefix.'vital_status'], $rules[$prefix.'dob_status']);
        $rules[$prefix.'id_missing_reason'] = ['nullable', Rule::in(array_keys(self::ID_MISSING_REASONS))];
        if (! $email) {
            unset($rules[$prefix.'email']);
        }
        if (! $contact) {
            unset($rules[$prefix.'phone'], $rules[$prefix.'alt_phone']);
        }

        return $rules;
    }

    private static function address(string $prefix): array
    {
        return [$prefix.'governorate_value_id' => ['nullable', 'integer', 'min:1'], $prefix.'locality_value_id' => ['nullable', 'integer', 'min:1'],
            $prefix.'area_value_id' => ['nullable', 'integer', 'min:1'], $prefix.'subarea_value_id' => ['nullable', 'integer', 'min:1'],
            $prefix.'address_other' => ['nullable', 'boolean'], $prefix.'address_details' => ['nullable', 'string', 'max:1000'],
            $prefix.'street' => ['nullable', 'string', 'max:200'], $prefix.'landmark' => ['nullable', 'string', 'max:200'],
            // Display names of the chosen places, kept for reading the request; the ids above are authoritative.
            $prefix.'governorate' => ['nullable', 'string', 'max:150'], $prefix.'city' => ['nullable', 'string', 'max:150'],
            $prefix.'area' => ['nullable', 'string', 'max:150'], $prefix.'neighborhood' => ['nullable', 'string', 'max:150']];
    }

    /** Arabic labels for the most visible fields; wildcard keys apply to every card. */
    public static function attributes(): array
    {
        $labels = [];
        foreach (['request.applicant.' => 'مقدم الطلب', 'father.' => 'الأب', 'mother.' => 'الأم', 'guardians.*.' => 'الوصي', 'children.*.' => 'اليتيم', 'household.members.*.' => 'فرد الأسرة'] as $prefix => $who) {
            $labels += Persons::attributes($prefix, $who);
        }

        return $labels + [
            'father.death.date' => 'تاريخ وفاة الأب', 'mother.death.date' => 'تاريخ وفاة الأم', 'father.missing.last_seen_on' => 'تاريخ آخر ظهور للأب',
            'mother.missing.last_seen_on' => 'تاريخ آخر ظهور للأم', 'household.declared_members' => 'عدد أفراد الأسرة', 'household.total_income' => 'إجمالي دخل الأسرة',
            'children.*.education.academic_year' => 'العام الدراسي', 'children.*.guardianship.ends_on' => 'نهاية سريان الوصاية',
        ];
    }

    /**
     * Cross-field checks. On a draft only contradictions that would corrupt data are reported (impossible dates,
     * duplicated identities). Submission adds the conditional requirements.
     *
     * @return array<string, string> field key => Arabic message
     */
    public static function issues(array $data, bool $submit, string $source): array
    {
        $errors = [];
        $children = array_values($data['children'] ?? []);
        $guardians = array_values($data['guardians'] ?? []);
        $guardianKeys = array_column($guardians, 'key');
        $date = function ($value): ?CarbonImmutable {
            try {
                return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
            } catch (\Throwable) {
                return null; // the type rule reports the bad date
            }
        };

        // One identity number belongs to one person in the request.
        $seen = [];
        foreach (self::people($data) as $key => $person) {
            [, $clean] = Identity::classify($person['id_number'] ?? null, isset($person['id_type_value_id']) ? (int) $person['id_type_value_id'] ?: null : null);
            if ($clean === '') {
                continue;
            }
            // The applicant may be the mother or a listed guardian: the same number there names the same person.
            if (isset($seen[$clean]) && $key !== 'request.applicant') {
                $errors[$key.'.id_number'] = 'رقم الهوية نفسه مكرر لشخصين في الطلب. الشخص الواحد يُسجل مرة واحدة (مثلًا: الأم الوصية تُختار «الأم» في قسم الوصي).';
            }
            if ($key !== 'request.applicant') {
                $seen[$clean] = true;
            }
            [$format] = Identity::classify($person['id_number'] ?? null, isset($person['id_type_value_id']) ? (int) $person['id_type_value_id'] ?: null : null);
            if ($submit && $format === 'invalid_format') {
                $errors[$key.'.id_number'] ??= 'صيغة رقم الهوية غير صحيحة حسب الصيغة المعتمدة للهوية الفلسطينية'.(($hint = Identity::hint()) ? ' ('.$hint.')' : '').'. اتركه فارغًا إن لم يكن متوفرًا.';
            }
            // A placeholder number is never stored as an identity (portal and staff alike): leave it empty and state why.
            if ($submit && $format === 'placeholder') {
                $errors[$key.'.id_number'] ??= 'هذا الرقم يبدو وهميًا. اتركه فارغًا إن لم يكن متوفرًا، واختر سبب عدم توفره.';
            }
        }

        // Life status: dates must follow birth and precede today; the missing-person report follows the last sighting.
        foreach (['father', 'mother'] as $parent) {
            $row = $data[$parent] ?? [];
            $birth = $date($row['date_of_birth'] ?? null);
            $status = $row['vital_status'] ?? null;
            $death = $date($row['death']['date'] ?? null);
            $seenOn = $date($row['missing']['last_seen_on'] ?? null);
            $reported = $date($row['missing']['reported_on'] ?? null);
            if ($status === 'deceased' && $death && $birth && $death->lt($birth)) {
                $errors[$parent.'.death.date'] = 'تاريخ الوفاة يسبق تاريخ الميلاد.';
            }
            if ($status === 'missing' && $seenOn && $birth && $seenOn->lt($birth)) {
                $errors[$parent.'.missing.last_seen_on'] = 'تاريخ آخر ظهور يسبق تاريخ الميلاد.';
            }
            if ($status === 'missing' && $reported && $seenOn && $reported->lt($seenOn)) {
                $errors[$parent.'.missing.reported_on'] = 'تاريخ الإبلاغ يسبق تاريخ آخر ظهور.';
            }
            // A death date never decides the status by itself (legacy report R5: doubtful maternal dates): with a
            // date the status must be stated as «متوفى»; otherwise the contradiction is resolved by a person.
            if ($death && $status !== 'deceased') {
                $errors[$parent.'.vital_status'] = 'يوجد تاريخ وفاة والحالة ليست «متوفى». حدّد الحالة الصحيحة أو احذف التاريخ.';
            }
            if ($submit) {
                // A parent's name is needed when it supports the orphan status (deceased or missing), or when the
                // mother is a guardian; otherwise it may follow later (RegistrationFieldSpec: father/mother.first_name).
                $named = in_array($status, ['deceased', 'missing'], true) || ($parent === 'mother' && in_array('mother', array_column($guardians, 'role'), true));
                if ($named && ! self::hasName($row)) {
                    $errors[$parent.'.first_name'] = 'اكتب الاسم الأول واسم العائلة على الأقل.';
                }
                if (blank($status)) {
                    $errors[$parent.'.vital_status'] = 'اختر الحالة؛ ويمكن اختيار «غير محدد» إن لم تكن معروفة.';
                }
                if ($status === 'deceased' && ! $death && empty($row['death']['date_unknown'])) {
                    $errors[$parent.'.death.date'] = 'اكتب تاريخ الوفاة، أو اختر «التاريخ غير معروف».';
                }
                if ($status === 'missing' && ! $seenOn && empty($row['missing']['date_unknown'])) {
                    $errors[$parent.'.missing.last_seen_on'] = 'اكتب تاريخ آخر ظهور، أو اختر «التاريخ غير معروف».';
                }
            }
        }
        foreach (['father', 'mother'] as $parent) {
            self::money($data, $parent.'.economic.', 'income_amount', 'currency', $errors);
            if ($submit && ($data[$parent]['economic']['benefits'] ?? null) === 'yes' && blank($data[$parent]['economic']['benefits_note'] ?? null)) {
                $errors[$parent.'.economic.benefits_note'] = 'اكتب نوع المعاش أو التعويض والجهة إن عُرفت.';
            }
        }
        $since = $date($data['mother']['marital_status_since'] ?? null);
        $motherDob = $date($data['mother']['date_of_birth'] ?? null);
        if ($since && $motherDob && $since->lt($motherDob)) {
            $errors['mother.marital_status_since'] = 'تاريخ تغيّر الحالة الاجتماعية يسبق تاريخ الميلاد.';
        }

        // Guardians: one entry per person; «الأم» reuses the mother's record instead of a second copy.
        $roles = array_count_values(array_filter(array_column($guardians, 'role')));
        foreach ($guardians as $i => $guardian) {
            $role = $guardian['role'] ?? null;
            if (($roles[$role] ?? 0) > 1 && in_array($role, ['applicant', 'mother'], true)) {
                $errors["guardians.$i.role"] = $role === 'mother' ? 'الأم مسجلة وصيةً مرة واحدة فقط.' : 'مقدم الطلب مسجل وصيًا مرة واحدة فقط.';
            }
            if ($role === 'mother' && ($data['mother']['vital_status'] ?? null) === 'deceased') {
                $errors["guardians.$i.role"] = 'لا يمكن أن تكون الأم المتوفاة وصية.';
            }
            self::money($data, "guardians.$i.economic.", 'income_amount', 'currency', $errors);
            if ($submit && $role === 'other' && ! self::hasName($guardian)) {
                $errors["guardians.$i.first_name"] = 'اكتب اسم الوصي (الاسم الأول واسم العائلة على الأقل).';
            }
            if ($submit && ! $role) {
                $errors["guardians.$i.role"] = 'حدد من هو الوصي.';
            }
        }

        // Children: each is an independent record with its own guardian relationship.
        $fatherDeath = ($data['father']['vital_status'] ?? null) === 'deceased' ? $date($data['father']['death']['date'] ?? null) : null;
        $motherBirth = $date($data['mother']['date_of_birth'] ?? null);
        foreach ($children as $i => $child) {
            $birth = $date($child['date_of_birth'] ?? null);
            if ($birth && $fatherDeath && $birth->gt($fatherDeath->addDays(300))) {
                $errors["children.$i.date_of_birth"] = 'تاريخ الميلاد بعد وفاة الأب بأكثر من 300 يوم؛ راجع التاريخين.';
            }
            if ($birth && $motherBirth && $birth->lte($motherBirth)) {
                $errors["children.$i.date_of_birth"] = 'تاريخ ميلاد الطفل لا يمكن أن يسبق ميلاد الأم.';
            }
            $starts = $date($child['guardianship']['starts_on'] ?? null);
            $ends = $date($child['guardianship']['ends_on'] ?? null);
            if ($starts && $ends && $ends->lt($starts)) {
                $errors["children.$i.guardianship.ends_on"] = 'نهاية سريان الوصاية تسبق بدايتها.';
            }
            $sStart = $date($child['sponsorship']['starts_on'] ?? null);
            $sEnd = $date($child['sponsorship']['ends_on'] ?? null);
            if ($sStart && $sEnd && $sEnd->lt($sStart)) {
                $errors["children.$i.sponsorship.ends_on"] = 'نهاية الكفالة تسبق بدايتها.';
            }
            self::money($data, "children.$i.sponsorship.", 'amount', 'currency', $errors);
            if (filled($child['guardian_key'] ?? null) && ! in_array($child['guardian_key'], $guardianKeys, true)) {
                $errors["children.$i.guardian_key"] = 'الوصي المختار لم يعد موجودًا في الطلب؛ اختر وصيًا آخر.';
            }
            if (! $submit) {
                continue;
            }
            if (! self::hasName($child)) {
                $errors["children.$i.first_name"] = 'اكتب اسم الطفل (الاسم الأول واسم العائلة على الأقل).';
            }
            // Sex is optional: unknown stays unknown and is never guessed from the name.
            if (blank($child['guardian_key'] ?? null)) {
                $errors["children.$i.guardian_key"] = 'حدد وصي هذا الطفل؛ لا يُسجل طفل دون وصي مرتبط به.';
            }
            if (blank($child['kinship_value_id'] ?? null)) {
                $errors["children.$i.kinship_value_id"] = 'حدد صلة الوصي بهذا الطفل.';
            }
            $educationCode = self::code($child['education']['status_value_id'] ?? null);
            if (in_array($educationCode, ['dropped_out', 'never_attended', 'not_enrolled'], true) && blank($child['education']['nonattendance_reason'] ?? null)) {
                $errors["children.$i.education.nonattendance_reason"] = 'اكتب سبب عدم الالتحاق أو التسرب.';
            }
            if (($child['sponsorship']['status'] ?? null) === 'yes' && blank($child['sponsorship']['organization'] ?? null)) {
                $errors["children.$i.sponsorship.organization"] = 'اكتب اسم الجهة الكافلة.';
            }
        }

        // Household totals: reported numbers are checked against each other, never rewritten.
        $h = $data['household'] ?? [];
        $declared = self::int($h['declared_members'] ?? null);
        $parts = array_map(fn (string $k): ?int => self::int($h[$k] ?? null), ['male_count', 'female_count', 'unknown_sex_count']);
        if ($declared !== null && array_filter($parts, fn ($v) => $v !== null) !== [] && array_sum($parts) !== $declared) {
            $errors['household.male_count'] = 'مجموع الذكور والإناث وغير المحدد ('.array_sum($parts).') لا يساوي عدد أفراد الأسرة ('.$declared.'). استخدم خانة «غير محدد» لمن لم يُذكر جنسه.';
        }
        if ($declared !== null && count($children) > $declared && blank($h['count_exception_reason'] ?? null)) {
            $errors['household.declared_members'] = 'عدد الأيتام في الطلب ('.count($children).') أكبر من عدد أفراد الأسرة. صحح العدد أو اكتب سبب الاستثناء.';
        }
        foreach (['studying_count', 'employed_count', 'dependents_count', 'earners_count', 'special_needs_count'] as $field) {
            if ($declared !== null && ($value = self::int($h[$field] ?? null)) !== null && $value > $declared) {
                $errors['household.'.$field] = 'العدد أكبر من عدد أفراد الأسرة ('.$declared.').';
            }
        }
        self::money($data, 'household.', 'total_income', 'currency', $errors);
        self::money($data, 'household.', 'expenses_amount', 'currency', $errors);
        self::money($data, 'household.', 'rent_amount', 'currency', $errors);
        $childKeys = array_column($children, 'key');
        foreach (array_values($h['aid'] ?? []) as $i => $aid) {
            self::money($data, "household.aid.$i.", 'amount', 'currency', $errors);
            if (filled($aid['child_key'] ?? null) && ! in_array($aid['child_key'], $childKeys, true)) {
                $errors["household.aid.$i.child_key"] = 'اليتيم المختار لهذه المساعدة لم يعد في الطلب؛ اختر «للأسرة كلها» أو يتيمًا آخر.';
            }
            if ($submit && blank($aid['organization'] ?? null)) {
                $errors["household.aid.$i.organization"] = 'اكتب اسم الجهة المقدمة للمساعدة.';
            }
        }
        if ($submit && ($h['external_aid'] ?? null) === 'yes' && empty($h['aid'])) {
            $errors['household.aid'] = 'أضف جهة مساعدة واحدة على الأقل، أو غيّر الإجابة.';
        }

        // Residence: displacement never replaces the original address; history dates must be in order.
        $r = $data['residence'] ?? [];
        if ($submit && ($r['displaced'] ?? null) === 'yes' && ! self::hasPlace($r['current'] ?? [])) {
            $errors['residence.current.governorate_value_id'] = 'الأسرة نازحة: حدد مكان السكن الحالي.';
        }
        $displacedSince = $date($r['displaced_since'] ?? null);
        $lastMoved = $date($r['last_moved_on'] ?? null);
        if ($displacedSince && $lastMoved && $lastMoved->lt($displacedSince)) {
            $errors['residence.last_moved_on'] = 'تاريخ آخر انتقال يسبق تاريخ بدء النزوح.';
        }
        if ($submit && ($r['hosted'] ?? null) === 'yes' && blank($r['host_note'] ?? null)) {
            $errors['residence.host_note'] = 'اكتب لدى من تقيم الأسرة (مثلًا: أسرة الخال)، دون بيانات حساسة.';
        }
        foreach (array_values($r['history'] ?? []) as $i => $move) {
            $from = $date($move['moved_on'] ?? null);
            $to = $date($move['left_on'] ?? null);
            if ($from && $to && $to->lt($from)) {
                $errors["residence.history.$i.left_on"] = 'تاريخ المغادرة يسبق تاريخ الانتقال.';
            }
        }

        if ($submit) {
            $applicant = $data['request']['applicant'] ?? [];
            if ($source === 'staff' && ! self::hasName($applicant)) {
                $errors['request.applicant.first_name'] = 'اكتب اسم مقدم الطلب.';
            }
            // Contact: any one phone in the request, or a documented reason (household.contact_unreachable_reason).
            if (blank($data['request']['relationship_value_id'] ?? null)) {
                $errors['request.relationship_value_id'] = 'حدد صلة مقدم الطلب بالأسرة.';
            }
            if ($children === []) {
                $errors['children'] = 'أضف يتيمًا واحدًا على الأقل.';
            }
            if ($guardians === []) {
                $errors['guardians'] = 'أضف وصيًا واحدًا على الأقل.';
            }
            if (empty($data['declaration']['truth'])) {
                $errors['declaration.truth'] = 'يلزم الإقرار بصحة البيانات قبل الإرسال.';
            }
            if (blank($data['declaration']['name'] ?? null)) {
                $errors['declaration.name'] = 'اكتب اسم المُقر كاملًا.';
            }
            if (empty($data['declaration']['consent'])) {
                $errors['declaration.consent'] = 'يلزم الموافقة على مراجعة البيانات والمستندات قبل الإرسال.';
            }
        }

        if ($submit) {
            $errors += RegistrationFieldSpec::errors($data, $source);
        }

        return $errors;
    }

    /**
     * Mismatches the reviewer must see (not errors): reported totals against the people actually entered.
     *
     * @return list<string>
     */
    public static function reconciliation(array $data): array
    {
        $notes = [];
        $h = $data['household'] ?? [];
        $declared = self::int($h['declared_members'] ?? null);
        $children = array_values($data['children'] ?? []);
        $linked = count(array_filter($children, fn ($c) => ($c['resides_with_household'] ?? true) !== false)) + count($h['members'] ?? [])
            + (($data['mother']['resides_with_household'] ?? null) === 'yes' ? 1 : 0)
            + count(array_filter($data['guardians'] ?? [], fn ($g) => ($g['role'] ?? null) !== 'mother' && ($g['resides_with_household'] ?? null) === 'yes'));
        if ($declared === null) {
            $notes[] = 'عدد أفراد الأسرة غير مذكور.';
        } elseif ($linked !== $declared) {
            $notes[] = "عدد الأفراد المعلن {$declared}، والأفراد المدخلون بأسمائهم {$linked}. العدد المعلن يبقى كما هو حتى تتحقق منه الزيارة.";
        }
        $enrolled = count(array_filter($children, fn ($c) => in_array(self::code($c['education']['status_value_id'] ?? null), ['enrolled', 'kindergarten', 'university'], true)));
        if (($studying = self::int($h['studying_count'] ?? null)) !== null && $studying < $enrolled) {
            $notes[] = "عدد الدارسين المعلن ({$studying}) أقل من الأيتام الملتحقين بالتعليم في الطلب ({$enrolled}).";
        }
        if (($data['residence']['displaced'] ?? null) === 'yes' && ! self::hasPlace($data['residence']['original'] ?? [])) {
            $notes[] = 'الأسرة نازحة ولم يُذكر عنوان السكن الأصلي.';
        }
        if (filled($h['assessed_economic_status_value_id'] ?? null) && filled($h['economic_status_value_id'] ?? null) && (int) $h['assessed_economic_status_value_id'] !== (int) $h['economic_status_value_id']) {
            $notes[] = 'الوضع الاقتصادي المُقيَّم من القسم يختلف عن المُفاد من الأسرة.';
        }
        if (filled($h['count_exception_reason'] ?? null)) {
            $notes[] = 'سبب استثناء الأعداد: '.$h['count_exception_reason'];
        }

        return $notes;
    }

    /**
     * Completion items for documents. A missing document is a task, never a blocker of the initial submission.
     *
     * @param  list<array{target: ?string, type_code: ?string}>  $documents
     * @return list<array{target: string, type: string, label: string}>
     */
    public static function missingDocuments(array $data, array $documents): array
    {
        $have = [];
        foreach ($documents as $doc) {
            $have[($doc['target'] ?? '').'|'.($doc['type_code'] ?? '')] = true;
        }
        $expected = [];
        foreach (array_values($data['children'] ?? []) as $child) {
            $name = self::displayName($child) ?: 'الطفل';
            $expected[] = ['child:'.$child['key'], 'birth_certificate', 'شهادة ميلاد '.$name];
            $guardian = collect($data['guardians'] ?? [])->firstWhere('key', $child['guardian_key'] ?? null);
            if ($guardian && ($guardian['role'] ?? null) !== 'mother') {
                $expected[] = ['child:'.$child['key'], 'guardianship_order', 'حجة الوصاية على '.$name];
            }
        }
        foreach (['father' => 'الأب', 'mother' => 'الأم'] as $parent => $label) {
            if (($data[$parent]['vital_status'] ?? null) === 'deceased') {
                $expected[] = [$parent, $parent.'_death_certificate', 'شهادة وفاة '.$label];
            }
            if (($data[$parent]['vital_status'] ?? null) === 'missing') {
                $expected[] = [$parent, 'missing_report', 'إثبات فقد '.$label];
            }
        }
        foreach (array_values($data['guardians'] ?? []) as $guardian) {
            $expected[] = ['guardian:'.$guardian['key'], ($guardian['role'] ?? null) === 'mother' ? 'mother_id' : 'guardian_id', 'هوية الوصي '.(self::displayName($guardian) ?: (($guardian['role'] ?? '') === 'mother' ? '(الأم)' : ''))];
        }

        return array_values(array_map(fn (array $e): array => ['target' => $e[0], 'type' => $e[1], 'label' => trim($e[2])],
            array_filter($expected, fn (array $e): bool => ! isset($have[$e[0].'|'.$e[1]]))));
    }

    /** Every person entry with its error-key prefix. @return array<string, array> */
    public static function people(array $data): array
    {
        $people = ['request.applicant' => $data['request']['applicant'] ?? [], 'father' => $data['father'] ?? [], 'mother' => $data['mother'] ?? []];
        foreach (array_values($data['guardians'] ?? []) as $i => $guardian) {
            if (($guardian['role'] ?? null) === 'other') {
                $people["guardians.$i"] = $guardian;
            }
        }
        foreach (array_values($data['children'] ?? []) as $i => $child) {
            $people["children.$i"] = $child;
        }
        foreach (array_values($data['household']['members'] ?? []) as $i => $member) {
            $people["household.members.$i"] = $member;
        }

        return $people;
    }

    /** The form without the staff-only answers (what the beneficiary portal may see or send). */
    public static function withoutStaffOnly(array $data): array
    {
        foreach (['father', 'mother'] as $parent) {
            unset($data[$parent]['death']['official_cause']);
        }
        unset($data['household']['assessed_economic_status_value_id'], $data['household']['legacy_file_no']);
        foreach ($data['children'] ?? [] as $i => $child) {
            unset($data['children'][$i]['social']['researcher_notes']);
        }

        return $data;
    }

    /** Puts back the staff-only answers saved earlier (a portal save can neither set nor erase them). */
    public static function keepStaffOnly(array $data, array $previous): array
    {
        $data = self::withoutStaffOnly($data);
        foreach (['father', 'mother'] as $parent) {
            if (filled($old = $previous[$parent]['death']['official_cause'] ?? null)) {
                $data[$parent]['death']['official_cause'] = $old;
            }
        }
        foreach (['assessed_economic_status_value_id', 'legacy_file_no'] as $key) {
            if (filled($old = $previous['household'][$key] ?? null)) {
                $data['household'][$key] = $old;
            }
        }
        $old = collect($previous['children'] ?? [])->keyBy('key');
        foreach ($data['children'] ?? [] as $i => $child) {
            if (filled($notes = $old->get($child['key'] ?? '')['social']['researcher_notes'] ?? null)) {
                $data['children'][$i]['social']['researcher_notes'] = $notes;
            }
        }

        return $data;
    }

    public static function hasName(array $person): bool
    {
        return (filled($person['first_name'] ?? null) && filled($person['family_name'] ?? null)) || filled($person['full_name'] ?? null);
    }

    public static function displayName(array $person): string
    {
        return trim((string) ($person['full_name'] ?? '')) ?: implode(' ', array_filter([$person['first_name'] ?? null, $person['father_name'] ?? null, $person['grandfather_name'] ?? null, $person['family_name'] ?? null]));
    }

    public static function hasPlace(array $address): bool
    {
        return filled($address['governorate_value_id'] ?? null) || filled($address['address_details'] ?? null);
    }

    /** Code of a reference value (codes drive conditional fields; names stay editable). */
    public static function code(mixed $valueId): ?string
    {
        static $codes = [];
        if (! filled($valueId)) {
            return null;
        }

        return $codes[(int) $valueId] ??= DB::table('master_data_values')->where('id', (int) $valueId)->value('code');
    }

    public static function normalizedName(array $person): string
    {
        return ArabicText::normalize(trim(($person['first_name'] ?? '').' '.($person['family_name'] ?? ''))) ?: ArabicText::normalize($person['full_name'] ?? '');
    }

    private static function money(array $data, string $prefix, string $amount, string $currency, array &$errors): void
    {
        if (filled(data_get($data, $prefix.$amount)) && blank(data_get($data, $prefix.$currency))) {
            $errors[$prefix.$currency] = 'حدد العملة لهذا المبلغ.';
        }
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
