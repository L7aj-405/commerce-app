@props(['name', 'value'])
<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-xs font-medium bg-slate-100 text-slate-700 border border-slate-200 whitespace-nowrap">
    <span class="text-slate-400 font-normal">{{ $name }}:</span>
    <span>{{ is_array($value) ? implode(', ', $value) : $value }}</span>
</span>
