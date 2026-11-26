<?php

// Run from the Laravel project root after copying package files.
$root = getcwd();
$backup = $root.DIRECTORY_SEPARATOR.'attendance_correction_backup_'.date('Ymd_His');
mkdir($backup, 0770, true);
function patchFile(string $file, callable $transform): void
{
    global $root, $backup;
    $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);
    if (! is_file($path)) {
        throw new RuntimeException("Missing: $file");
    }
    $old = file_get_contents($path);
    $new = $transform($old);
    if ($new === $old) {
        throw new RuntimeException("No changes made: $file (possibly already patched)");
    }
    $b = $backup.DIRECTORY_SEPARATOR.str_replace('/', '__', $file);
    copy($path, $b);
    file_put_contents($path, $new);
    echo "PATCHED $file; BACKUP $b\n";
}
function replaceOnce(string $s, string $old, string $new, string $file): string
{
    $n = substr_count($s, $old);
    if ($n !== 1) {
        throw new RuntimeException("Expected once, found $n: $file: ".substr($old, 0, 100));
    }

    return str_replace($old, $new, $s);
}
patchFile('routes/web.php', function ($s) {
    $a = 'use App\\Http\\Controllers\\Admin\\AttendanceOperationsController;';
    $s = replaceOnce($s, $a, $a."\nuse App\\Http\\Controllers\\Admin\\AttendancePunchCorrectionController;", 'routes');
    $anchor = "    Route::get('/operations', [AttendanceOperationsController::class, 'overview'])->name('operations');";
    $new = $anchor."\n    Route::post('/punches/{punch}/correction', [AttendancePunchCorrectionController::class, 'requestCorrection'])->whereNumber('punch')->middleware('throttle:10,1')->name('punches.correction.request');\n    Route::post('/corrections/{correction}/decision', [AttendancePunchCorrectionController::class, 'decide'])->whereNumber('correction')->middleware('throttle:10,1')->name('punches.correction.decision');\n    Route::post('/corrections/{correction}/revoke', [AttendancePunchCorrectionController::class, 'revoke'])->whereNumber('correction')->middleware('throttle:10,1')->name('punches.correction.revoke');";

    return replaceOnce($s, $anchor, $new, 'routes');
});
patchFile('app/Http/Controllers/Admin/AttendanceOperationsController.php', function ($s) {
    $anchor = "            'daily' => DB::table('attendance_daily_results')->orderByDesc('work_date')->limit(100)->get()];";
    $replacement = "            'daily' => DB::table('attendance_daily_results')->orderByDesc('work_date')->limit(100)->get(),\n            'correction_punches' => DB::table('attendance_punches as p')->join('employees as e', 'e.id', '=', 'p.employee_id')\n                ->where('p.source', 'device')->whereNotNull('p.raw_payload')->whereNotNull('p.employee_id')\n                ->orderByDesc('p.occurred_at')->limit(100)\n                ->get(['p.id', 'p.direction', 'p.occurred_at', 'p.raw_user_id', 'e.employee_number', 'e.first_name_ar', 'e.family_name_ar']),\n            'corrections' => DB::table('attendance_punch_corrections as c')->join('attendance_punches as p', 'p.id', '=', 'c.punch_id')\n                ->orderByDesc('c.id')->limit(100)->get(['c.id', 'c.punch_id', 'c.original_direction', 'c.corrected_direction', 'c.reason', 'c.status', 'c.requested_by', 'c.reviewed_by', 'c.review_note', 'c.created_at', 'p.raw_user_id', 'p.occurred_at']),\n        ];";

    return replaceOnce($s, $anchor, $replacement, 'overview');
});
function patchDirectionQuery(string $s, string $file): string
{
    $pattern = "/\\\$punches = DB::table\\('attendance_punches'\\).*?->orderBy\\('occurred_at'\\)->get\\(\\['direction', 'occurred_at'\\]\\);/s";
    $start = $file === 'daily' ? '$start->format(\'Y-m-d H:i:sP\')' : '$start->format(\'Y-m-d H:i:sP\')';
    $end = '$end->format(\'Y-m-d H:i:sP\')';
    $new = <<<'CODE'
$punches = DB::table('attendance_punches as p')
                ->leftJoinSub(DB::table('attendance_punch_corrections')->where('status', 'approved')->select(['punch_id', 'corrected_direction']), 'correction', function ($join) {
                    $join->on('correction.punch_id', '=', 'p.id');
                })
                ->where('p.employee_id', __EMPLOYEE__)
                ->where(function ($q) {
                    $q->whereNull('p.raw_payload')->orWhereIn('p.match_status', ['matched', 'reviewed']);
                })
                ->where('p.occurred_at', '>=', $start->format('Y-m-d H:i:sP'))
                ->where('p.occurred_at', '<', $end->format('Y-m-d H:i:sP'))
                ->orderBy('p.occurred_at')
                ->get([DB::raw('COALESCE(correction.corrected_direction, p.direction) as direction'), 'p.occurred_at']);
CODE;
    $new = str_replace('__EMPLOYEE__', $file === 'daily' ? '$employeeId' : '$work->employee_id', $new);
    $changed = preg_replace_callback($pattern, fn () => $new, $s, -1, $count);
    if ($changed === null || $count !== 1) {
        throw new RuntimeException("Cannot reliably locate punch query ($count): $file");
    }

    return $changed;
}
patchFile('app/Services/Attendance/AttendanceDailyService.php', fn ($s) => patchDirectionQuery($s, 'daily'));
patchFile('app/Services/Attendance/CompensatoryLeaveService.php', fn ($s) => patchDirectionQuery($s, 'compensatory'));
patchFile('resources/js/Pages/Admin/Attendance/Operations.vue', function ($s) {
    $s = replaceOnce($s, '    daily: { type: Array, default: () => [] },', "    daily: { type: Array, default: () => [] },\n    correction_punches: { type: Array, default: () => [] },\n    corrections: { type: Array, default: () => [] },", 'vue props');
    $anchor = "const review = useForm({ reason: '' });";
    $functions = <<<'JS'
const correctionForm = useForm({ corrected_direction: 'in', reason: 'اختيار خروج بدل دخول بالخطأ' });
const decisionForm = useForm({ decision: 'approve', review_note: '' });
const revokeForm = useForm({ reason: '' });
const searchPunch = ref('');
const displayPunches = computed(() => {
    const term = searchPunch.value.trim();
    return correction_punches.value.filter(p => !term || [p.first_name_ar, p.family_name_ar, p.employee_number].some(x => String(x ?? '').includes(term)));
});
function requestCorrection(p) {
    if (!window.confirm(`إرسال طلب تصحيح الحركة #${p.id} إلى ${correctionForm.corrected_direction === 'in' ? 'دخول' : 'خروج'}؟`)) return;
    correctionForm.post(`/admin/attendance/punches/${p.id}/correction`, { preserveScroll: true });
}
function decideCorrection(c, decision) {
    if (!window.confirm(`تأكيد ${decision === 'approve' ? 'اعتماد' : 'رفض'} التصحيح #${c.id}؟`)) return;
    decisionForm.decision = decision;
    decisionForm.post(`/admin/attendance/corrections/${c.id}/decision`, { preserveScroll: true });
}
function revokeCorrection(c) {
    const reason = window.prompt('سبب إلغاء التصحيح المعتمد (10 أحرف على الأقل):');
    if (!reason) return;
    revokeForm.reason = reason;
    revokeForm.post(`/admin/attendance/corrections/${c.id}/revoke`, { preserveScroll: true });
}
JS;
    $s = replaceOnce($s, "import { Link, useForm } from '@inertiajs/vue3';", "import { Link, useForm } from '@inertiajs/vue3';\nimport { ref, computed, toRef } from 'vue';", 'imports');
    // defineProps return is required to reference list in script
    $s = replaceOnce($s, 'defineProps({', 'const props = defineProps({', 'props');
    $functions = str_replace("const searchPunch = ref('');", "const correction_punches = toRef(props, 'correction_punches');\nconst searchPunch = ref('');", $functions);
    $s = replaceOnce($s, $anchor, $functions."\n".$anchor, 'vue methods');
    $section = <<<'VUE'
            <section class="card space-y-3">
                <h2 class="card-title">تصحيح اتجاه البصمة — طلب وموافقة إدارية</h2>
                <p>بيانات جهاز البصمة الأصلية محفوظة دون تعديل. ابحث باسم الموظف ضمن أحدث 100 حركة، ثم قدم طلب تصحيح مبررًا.</p>
                <input v-model="searchPunch" class="form-input w-full" placeholder="ابحث باسم الموظف" aria-label="البحث باسم الموظف" />
                <div class="flex flex-wrap gap-3 items-center">
                    <label>الاتجاه المقترح
                        <select v-model="correctionForm.corrected_direction" class="form-input"><option value="in">دخول</option><option value="out">خروج</option></select>
                    </label>
                    <label class="flex-1">سبب الطلب
                        <input v-model="correctionForm.reason" class="form-input w-full" maxlength="1000" aria-label="سبب التصحيح" />
                    </label>
                </div>
                <p v-for="(error, key) in correctionForm.errors" :key="key" class="field-error">{{error}}</p>
                <div class="max-h-72 overflow-auto">
                    <table class="w-full text-right text-sm"><thead><tr><th>الموظف</th><th>وقت الحركة</th><th>الاتجاه الأصلي</th><th>الإجراء</th></tr></thead>
                    <tbody><tr v-for="p in displayPunches" :key="p.id"><td>{{p.first_name_ar}} {{p.family_name_ar}}</td><td>{{p.occurred_at}}</td><td>{{p.direction}}</td><td><button class="btn btn-secondary" :disabled="correctionForm.processing" @click="requestCorrection(p)">طلب تصحيح</button></td></tr></tbody></table>
                </div>
                <h3 class="font-semibold">طلبات التصحيح (آخر 100)</h3>
                <label class="block">ملاحظات قرار الإدارة (اختيارية)<textarea v-model="decisionForm.review_note" class="form-input w-full" maxlength="1000" rows="2" placeholder="ملاحظات الاعتماد أو الرفض"></textarea></label>
                <p v-for="(error, key) in decisionForm.errors" :key="key" class="field-error">{{error}}</p>
                <p v-for="(error, key) in revokeForm.errors" :key="key" class="field-error">{{error}}</p>
                <div class="max-h-80 overflow-auto"><table class="w-full text-right text-sm"><thead><tr><th>رقم</th><th>البصمة</th><th>الأصلي</th><th>المطلوب</th><th>الحالة</th><th>السبب</th><th>قرار الإدارة</th></tr></thead>
                    <tbody><tr v-for="c in corrections" :key="c.id"><td>{{c.id}}</td><td>{{c.punch_id}}</td><td>{{c.original_direction}}</td><td>{{c.corrected_direction}}</td><td>{{c.status}}</td><td>{{c.reason}}</td><td>
                        <template v-if="canApproveImport && c.status === 'pending'"><button class="btn btn-secondary" :disabled="decisionForm.processing" @click="decideCorrection(c,'approve')">اعتماد</button> <button class="btn btn-secondary" :disabled="decisionForm.processing" @click="decideCorrection(c,'reject')">رفض</button></template>
                        <button v-if="canApproveImport && c.status === 'approved'" class="btn btn-secondary" :disabled="revokeForm.processing" @click="revokeCorrection(c)">إلغاء الاعتماد</button>
                    </td></tr></tbody></table></div>
            </section>
VUE;
    $anchor = '            <section class="card space-y-3">';
    $position = strpos($s, $anchor);
    if ($position === false) {
        throw new RuntimeException('Vue section insertion point not found');
    }

    return substr_replace($s, $section."\n", $position, 0);
});
echo "Patch complete. Run php -l, build and targeted migration after review.\n";
