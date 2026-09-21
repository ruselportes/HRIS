<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a sign-in hands the client. The full employee record must never sit
 * in browser storage: `auth/login` and `auth/me` used to return the whole
 * EmployeeResource — daily rate, TIN, SSS, PhilHealth, Pag-IBIG, date of
 * birth, address, blood type — and the web app persists it in localStorage
 * (`hris.user`), where it survives the tab closing. W3 puts workers on
 * shared phones and computers, so the record is id, code, names, role and
 * site only. Pages that need more already read the `employees` endpoints.
 *
 * The two fields beyond the minimum are load-bearing for the web shell:
 * LeavePage renders "you" from `full_name` and filters the engineer's
 * people search by `site.site_id`. Everyone signed in before this change
 * must sign in again to flush the stored full copy.
 */
class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'employee_id' => $this->employee_id,
            'employee_code' => $this->employee_code,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'role' => $this->whenLoaded('role', fn () => [
                'role_id' => $this->role->role_id,
                'role_name' => $this->role->role_name,
                'slug' => $this->role->slug,
            ]),
            'site' => $this->whenLoaded('site', fn () => $this->site === null ? null : [
                'site_id' => $this->site->site_id,
                'site_name' => $this->site->site_name,
            ]),
        ];
    }
}
