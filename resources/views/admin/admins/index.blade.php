@extends('layouts.portal')

@section('title', 'Admins')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">ACCESS CONTROL</span><h1>Admins</h1><p>Create and manage administrators with access to every location.</p></div></div>
    @if (session('status'))<div class="success-message" role="status">{{ session('status') }}</div>@endif
    @error('admin')<div class="error-message" role="alert">{{ $message }}</div>@enderror

    <div class="admin-management-grid">
        <section class="panel create-panel"><div class="panel-heading"><div><h3>New admin</h3></div></div><p class="panel-description">Admins can manage managers and access every location.</p>
            <form method="POST" action="{{ route('admin.admins.store') }}" class="form-stack" data-ajax>@csrf
                <div class="field"><label for="admin_name">Full name</label><input id="admin_name" name="name" type="text" autocomplete="name" required><span class="field-error" data-error-for="name" role="alert"></span></div>
                <div class="field"><label for="admin_email">Email address</label><input id="admin_email" name="email" type="email" autocomplete="off" required><span class="field-error" data-error-for="email" role="alert"></span></div>
                <div class="field"><label for="admin_password">Password</label><input id="admin_password" name="password" type="password" autocomplete="new-password" minlength="12" required><span class="field-error" data-error-for="password" role="alert"></span></div>
                <div class="field"><label for="admin_password_confirmation">Confirm password</label><input id="admin_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required></div>
                <span class="form-error" data-form-error role="alert"></span>
                <button class="button button-primary" type="submit">Create admin <span aria-hidden="true">→</span></button>
            </form>
        </section>

        <section class="panel"><div class="panel-heading"><div><h3>Admin accounts</h3></div><span class="panel-note">{{ $admins->count() }} total</span></div>
            <div class="admin-account-list">
                @forelse ($admins as $admin)
                    <div class="admin-account-card">
                        <form method="POST" action="{{ route('admin.admins.update', $admin) }}" class="admin-edit-form" data-ajax>@csrf @method('PATCH')
                            <div class="field"><label for="admin-name-{{ $admin->id }}">Full name</label><input id="admin-name-{{ $admin->id }}" name="name" value="{{ $admin->name }}" required><span class="field-error" data-error-for="name" role="alert"></span></div>
                            <div class="field"><label for="admin-email-{{ $admin->id }}">Email address</label><input id="admin-email-{{ $admin->id }}" name="email" type="email" value="{{ $admin->email }}" required><span class="field-error" data-error-for="email" role="alert"></span></div>
                            <div class="admin-password-fields">
                                <div class="field"><label for="admin-password-{{ $admin->id }}">New password</label><input id="admin-password-{{ $admin->id }}" name="password" type="password" autocomplete="new-password" minlength="12"><span class="field-error" data-error-for="password" role="alert"></span></div>
                                <div class="field"><label for="admin-password-confirmation-{{ $admin->id }}">Confirm new password</label><input id="admin-password-confirmation-{{ $admin->id }}" name="password_confirmation" type="password" autocomplete="new-password" minlength="12"></div>
                            </div>
                            <span class="form-error" data-form-error role="alert"></span>
                            <div class="admin-actions"><button class="button button-primary" type="submit">Save changes</button></div>
                        </form>
                        <form method="POST" action="{{ route('admin.admins.destroy', $admin) }}" class="admin-delete-form" data-ajax data-confirm="Delete {{ $admin->name }}'s admin account? This cannot be undone." data-confirm-title="Delete admin" data-confirm-label="Delete admin">@csrf @method('DELETE')<button class="button option-delete-button" type="submit">Delete</button><span class="form-error" data-form-error role="alert"></span></form>
                    </div>
                @empty
                    <div class="empty-state"><strong>No admin accounts</strong><p>Create the first admin using the form.</p></div>
                @endforelse
            </div>
        </section>
    </div>
@endsection
