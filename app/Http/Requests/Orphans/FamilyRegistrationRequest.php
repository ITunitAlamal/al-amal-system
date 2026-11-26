<?php

namespace App\Http\Requests\Orphans;

use App\Services\Configuration\GazaAddress;
use App\Services\Orphans\Registration\RegistrationForm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Save-draft and submit of a family registration request, portal or staff. A draft accepts partial answers. Submit
 * applies the conditional requirements of RegistrationForm::issues(). Ownership is checked by
 * FamilyRegistrations::assertOwner().
 */
final class FamilyRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return RegistrationForm::rules();
    }

    public function attributes(): array
    {
        return RegistrationForm::attributes();
    }

    public function submitting(): bool
    {
        return str_ends_with((string) $this->route()?->getName(), '.submit');
    }

    public function source(): string
    {
        return str_starts_with((string) $this->route()?->getName(), 'guardian.') ? 'portal' : 'staff';
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $data = $validator->validated();
            foreach (RegistrationForm::issues($data, $this->submitting(), $this->source()) as $key => $message) {
                $validator->errors()->add($key, $message);
            }
            // Governorate → city → area → neighbourhood must belong together (the same rule as every AIMS address).
            // A draft may hold a half-chosen address; the hierarchy is enforced on submission.
            if (! $this->submitting()) {
                return;
            }
            $addresses = ['mother.address' => $data['mother']['address'] ?? null, 'residence.original' => $data['residence']['original'] ?? null, 'residence.current' => $data['residence']['current'] ?? null];
            foreach (array_values($data['guardians'] ?? []) as $i => $guardian) {
                $addresses["guardians.$i.address"] = $guardian['address'] ?? null;
            }
            foreach (array_values($data['residence']['history'] ?? []) as $i => $move) {
                $addresses["residence.history.$i"] = $move;
            }
            foreach (array_filter($addresses) as $prefix => $address) {
                if (! RegistrationForm::hasPlace($address)) {
                    continue;
                }
                try {
                    app(GazaAddress::class)->normalize($address, null, '', false);
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        $validator->errors()->add($prefix.'.'.$field, $messages[0]);
                    }
                }
            }
        }];
    }
}
