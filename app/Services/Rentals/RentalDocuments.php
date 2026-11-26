<?php

namespace App\Services\Rentals;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents of the rentals file (plans, identity and registration papers, signed contracts, handover, inspection and
 * eviction records, meter photos). Kept on the private disk, downloaded only through an authorised route, and
 * versioned: a new version supersedes the previous one, which stays readable.
 */
final class RentalDocuments
{
    /** @var array<string, string> owner type => table */
    public const OWNERS = ['property' => 'aims_properties', 'unit' => 'aims_property_units', 'tenant' => 'aims_rental_tenants', 'application' => 'aims_rental_applications',
        'contract' => 'aims_rental_contracts', 'case' => 'aims_rental_cases', 'settlement' => 'aims_rental_settlements', 'reading' => 'aims_rental_meter_readings'];

    public const MIMES = 'pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx';

    public function __construct(private readonly RentalConstants $constants, private readonly RentalAudit $audit) {}

    public function store(User $actor, string $ownerType, int $ownerId, UploadedFile $file, ?int $typeValueId, string $title, ?int $supersedesId): int
    {
        abort_unless(isset(self::OWNERS[$ownerType]) && DB::table(self::OWNERS[$ownerType])->where('id', $ownerId)->exists(), 404);
        $this->constants->assert('rental_document_types', $typeValueId, 'document_type_value_id');
        $version = 1;
        if ($supersedesId !== null) {
            $previous = DB::table('aims_rental_documents')->where('id', $supersedesId)->where('owner_type', $ownerType)->where('owner_id', $ownerId)->first();
            if (! $previous || DB::table('aims_rental_documents')->where('supersedes_id', $supersedesId)->exists()) {
                throw ValidationException::withMessages(['supersedes_id' => 'المستند السابق غير موجود في هذا الملف أو له إصدار أحدث.']);
            }
            $version = (int) $previous->version + 1;
            $typeValueId ??= $previous->document_type_value_id ? (int) $previous->document_type_value_id : null;
        }
        $path = $file->store("rentals/{$ownerType}/{$ownerId}", 'local');
        $id = DB::table('aims_rental_documents')->insertGetId([
            'owner_type' => $ownerType, 'owner_id' => $ownerId, 'document_type_value_id' => $typeValueId, 'title' => $title, 'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'mime' => (string) $file->getMimeType(), 'size_bytes' => (int) $file->getSize(),
            'version' => $version, 'supersedes_id' => $supersedesId, 'uploaded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->log((int) $actor->id, 'rental.document.uploaded', 'aims_rental_documents', $id, null,
            ['owner' => $ownerType.':'.$ownerId, 'title' => $title, 'version' => $version, 'supersedes' => $supersedesId]);

        return $id;
    }

    /**
     * Documents of one record, newest first, with the type and whether a newer version replaced each.
     *
     * @return list<array<string, mixed>>
     */
    public function list(string $ownerType, int $ownerId): array
    {
        $superseded = DB::table('aims_rental_documents')->whereNotNull('supersedes_id')->pluck('supersedes_id')->map(fn ($id): int => (int) $id)->all();

        return DB::table('aims_rental_documents as d')->leftJoin('master_data_values as v', 'v.id', '=', 'd.document_type_value_id')
            ->leftJoin('users as u', 'u.id', '=', 'd.uploaded_by')
            ->where('d.owner_type', $ownerType)->where('d.owner_id', $ownerId)->orderByDesc('d.id')
            ->get(['d.id', 'd.title', 'd.original_name', 'd.mime', 'd.size_bytes', 'd.version', 'd.supersedes_id', 'd.created_at', 'v.name_ar as type', 'v.code as type_code', 'u.name as uploaded_by'])
            ->map(fn (object $row): array => (array) $row + ['current' => ! in_array((int) $row->id, $superseded, true)])->all();
    }

    public function owner(int $documentId): ?object
    {
        return DB::table('aims_rental_documents')->where('id', $documentId)->first();
    }

    public function download(User $actor, object $document): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($document->path), 404, 'الملف غير موجود على الخادم.');
        $this->audit->log((int) $actor->id, 'rental.document.downloaded', 'aims_rental_documents', (int) $document->id, null, ['owner' => $document->owner_type.':'.$document->owner_id]);

        return Storage::disk('local')->download($document->path, $document->original_name);
    }
}
