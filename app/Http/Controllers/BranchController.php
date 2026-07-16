<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Package;
use App\Models\Router;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function index(): View
    {
        return view('branches.index', [
            'branches' => Branch::with(['packages:id,name', 'router:id,name'])->withCount('customers')->orderBy('name')->get(),
            'packages' => Package::where('is_active', true)->orderBy('name')->get(),
            'routers' => Router::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $packageIds] = $this->validated($request);
        $branch = Branch::create($data);
        $branch->packages()->sync($packageIds);

        return back()->with('success', 'Branch added successfully.');
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        [$data, $packageIds] = $this->validated($request, $branch);
        $branch->update($data);
        $branch->packages()->sync($packageIds);

        return back()->with('success', 'Branch updated successfully.');
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        if ($branch->customers()->exists()) {
            return back()->with('error', 'This branch is assigned to customers. Move those customers before deleting it.');
        }
        if ($branch->users()->exists()) {
            return back()->with('error', 'This branch is assigned to panel users. Move or delete those users first.');
        }

        $branch->delete();

        return back()->with('success', 'Branch deleted.');
    }

    private function validated(Request $request, ?Branch $branch = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('branches', 'name')->ignore($branch)],
            'router_id' => ['nullable', Rule::exists('routers', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'operator_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ott_deduction' => ['nullable', 'numeric', 'min:0'],
            'package_ids' => ['nullable', 'array'],
            'package_ids.*' => ['integer', 'distinct', 'exists:packages,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $packageIds = $data['package_ids'] ?? [];
        unset($data['package_ids']);

        return [$data + ['is_active' => $request->boolean('is_active')], $packageIds];
    }
}
