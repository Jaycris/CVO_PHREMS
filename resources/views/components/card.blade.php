@props(['padding' => true])

<div {{ $attributes->merge(['class' => 'professional-panel' . ($padding ? ' p-6' : '')]) }}>
    {{ $slot }}
</div>
