Hello,

@foreach ($lines as $line)
{!! $line !!}

@endforeach
Your code: {!! $code !!}

This code expires in {!! $ttlMinutes !!} minutes.

{!! $ignore !!}

Regards,
{!! $brand !!}
