@extends('layouts.app')

@section('title', 'إدارة الهيكل التنظيمي')

@section('content')
<div class="card">
    <h1>إدارة الهيكل التنظيمي والأقسام</h1>
    <p class="muted">جمعية معهد الأمل للأيتام – AIMS</p>

    <a href="{{ route('dashboard') }}">العودة للوحة التحكم</a>

    @if(session('success'))
        <p style="background:#dcfce7;padding:14px;border-radius:8px">
            {{ session('success') }}
        </p>
    @endif

    @if($errors->any())
        <div style="background:#fee2e2;padding:14px;border-radius:8px">
            @foreach($errors->all() as $error)
                <p class="danger">{{ $error }}</p>
            @endforeach
        </div>
    @endif
</div>

<div class="card" style="margin-top:20px">
    <h2>إضافة إدارة أو قسم جديد</h2>

    <form method="POST" action="{{ route('admin.departments.store') }}">
        @csrf

        <label>رمز القسم</label>
        <input name="code" value="{{ old('code') }}"
               maxlength="50" required>

        <label>اسم الإدارة أو القسم</label>
        <input name="name" value="{{ old('name') }}"
               maxlength="255" required>

        <label>الإدارة التابعة لها</label>
        <select name="parent_id" style="width:100%;padding:12px">
            <option value="">إدارة رئيسية مستقلة</option>
            @foreach($departments as $parent)
                @if($parent->is_active)
                    <option value="{{ $parent->id }}"
                        @selected(old('parent_id') == $parent->id)>
                        {{ $parent->name }}
                    </option>
                @endif
            @endforeach
        </select>

        <button type="submit" style="margin-top:18px">
            إضافة القسم
        </button>
    </form>
</div>

<div class="card" style="margin-top:20px">
    <h2>الأقسام والإدارات المسجلة</h2>

    <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;text-align:right">
            <thead>
                <tr style="background:#edf2f7">
                    <th style="padding:12px">الرمز</th>
                    <th>الاسم</th>
                    <th>يتبع إلى</th>
                    <th>الحالة</th>
                    <th>الإجراءات</th>
                </tr>
            </thead>
            <tbody>
                @forelse($departments as $department)
                    <tr style="border-bottom:1px solid #ddd">
                        <td style="padding:12px">{{ $department->code }}</td>
                        <td>{{ $department->name }}</td>
                        <td>
                            {{ $departments->firstWhere('id', $department->parent_id)?->name ?? 'رئيسية' }}
                        </td>
                        <td>
                            {{ $department->is_active ? 'نشط' : 'موقوف' }}
                        </td>
                        <td>
                            <button type="button"
                                onclick="document.getElementById('edit-{{ $department->id }}').hidden = !document.getElementById('edit-{{ $department->id }}').hidden">
                                تعديل
                            </button>

                            <form method="POST"
                                action="{{ route('admin.departments.toggle', $department) }}"
                                style="display:inline"
                                onsubmit="return confirm('هل تؤكد تغيير حالة القسم؟')">
                                @csrf
                                @method('PATCH')
                                <button type="submit">
                                    {{ $department->is_active ? 'إيقاف' : 'تفعيل' }}
                                </button>
                            </form>
                        </td>
                    </tr>

                    <tr id="edit-{{ $department->id }}" hidden>
                        <td colspan="5" style="padding:20px;background:#f8fafc">
                            <h3>تعديل {{ $department->name }}</h3>

                            <form method="POST"
                                action="{{ route('admin.departments.update', $department) }}">
                                @csrf
                                @method('PUT')

                                <label>الرمز</label>
                                <input name="code"
                                    value="{{ $department->code }}" required>

                                <label>الاسم</label>
                                <input name="name"
                                    value="{{ $department->name }}" required>

                                <label>الإدارة التابعة لها</label>
                                <select name="parent_id"
                                    style="width:100%;padding:12px">
                                    <option value="">إدارة رئيسية</option>

                                    @foreach($departments as $parent)
                                        @if($parent->is_active && $parent->id !== $department->id)
                                            <option value="{{ $parent->id }}"
                                                @selected($department->parent_id == $parent->id)>
                                                {{ $parent->name }}
                                            </option>
                                        @endif
                                    @endforeach
                                </select>

                                <button type="submit" style="margin-top:12px">
                                    حفظ التعديلات
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="padding:18px">
                            لا توجد أقسام مسجلة.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
