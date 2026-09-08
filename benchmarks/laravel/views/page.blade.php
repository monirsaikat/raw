@extends('bench.layout')

@section('title', 'Laravel — Benchmark page')

@section('content')
    <section>
        <h1>Build something great, faster</h1>
        <div class="rank-list">
            @foreach ($items as $item)
                <div class="rank-row">
                    <div class="rank-number">#{{ $item['rank'] }}</div>
                    <div class="rank-body"><h3>{{ $item['title'] }}</h3><p>{{ $item['description'] }}</p></div>
                    <div class="rank-meta">{{ $item['meta'] }}</div>
                </div>
            @endforeach
        </div>
        <form method="post" action="{{ url('/contact') }}">
            @csrf
            <button type="submit">Send</button>
        </form>
    </section>
@endsection
