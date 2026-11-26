<?php

namespace App\Services\Orphans;

/**
 * Infers the applicant's kinship to a child from official parent identities only (SRS-002 §6).
 *
 * Every identity is an HMAC hash (RegistryIdentity::hash). Names are never compared.
 * Kinship never implies legal authority to represent the child; that is checked separately.
 */
final class FamilyRelationshipResolver
{
    public const LABELS = [
        'mother' => 'الأم',
        'paternal_grandparent' => 'جد/جدة لأب',
        'maternal_grandparent' => 'جد/جدة لأم',
        'paternal_uncle_aunt' => 'عم/عمة',
        'maternal_uncle_aunt' => 'خال/خالة',
        'sibling' => 'أخ/أخت',
        'claims_to_be_father' => 'مقدم الطلب هو الأب المسجل',
        'is_the_child' => 'مقدم الطلب هو الطفل نفسه',
        'none' => 'لا قرابة مثبتة من المعرفات',
        'unknown' => 'تعذر التحديد لنقص المعرفات',
    ];

    /**
     * @param  array{id: string, father?: ?string, mother?: ?string}  $applicant
     * @param  array{id?: ?string, father?: ?string, mother?: ?string, father_father?: ?string, father_mother?: ?string, mother_father?: ?string, mother_mother?: ?string}  $child
     */
    public function resolve(array $applicant, array $child): string
    {
        $self = $applicant['id'];
        $same = static fn (?string $a, ?string $b): bool => $a !== null && $b !== null && hash_equals($a, $b);

        if ($same($self, $child['id'] ?? null)) {
            return 'is_the_child';
        }
        if ($same($self, $child['father'] ?? null)) {
            return 'claims_to_be_father';
        }
        if ($same($self, $child['mother'] ?? null)) {
            return 'mother';
        }
        if ($same($self, $child['father_father'] ?? null) || $same($self, $child['father_mother'] ?? null)) {
            return 'paternal_grandparent';
        }
        if ($same($self, $child['mother_father'] ?? null) || $same($self, $child['mother_mother'] ?? null)) {
            return 'maternal_grandparent';
        }

        $applicantFather = $applicant['father'] ?? null;
        $applicantMother = $applicant['mother'] ?? null;

        if ($same($applicantFather, $child['father'] ?? null) || $same($applicantMother, $child['mother'] ?? null)) {
            return 'sibling';
        }
        if ($same($applicantFather, $child['father_father'] ?? null) || $same($applicantMother, $child['father_mother'] ?? null)) {
            return 'paternal_uncle_aunt';
        }
        if ($same($applicantFather, $child['mother_father'] ?? null) || $same($applicantMother, $child['mother_mother'] ?? null)) {
            return 'maternal_uncle_aunt';
        }

        $knowsApplicantParents = $applicantFather !== null || $applicantMother !== null;
        $knowsChildLineage = ($child['father'] ?? null) !== null || ($child['mother'] ?? null) !== null;

        return $knowsApplicantParents && $knowsChildLineage ? 'none' : 'unknown';
    }
}
