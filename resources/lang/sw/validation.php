<?php

declare(strict_types=1);

/**
 * Ujumbe wa uthibitishaji wa fomu kwa Kiswahili. `:attribute` ni jina la
 * sehemu inayothibitishwa; herufi nyingine (`:min`, `:max`, `:values`)
 * hutolewa na kanuni husika.
 */
return [
    'required' => 'Sehemu ya :attribute inahitajika.',
    'string' => 'Sehemu ya :attribute lazima iwe maandishi.',
    'control_characters' => 'Sehemu ya :attribute ina herufi zisizoruhusiwa.',
    'array' => 'Sehemu ya :attribute lazima iwe safu.',
    'integer' => 'Sehemu ya :attribute lazima iwe namba nzima.',
    'numeric' => 'Sehemu ya :attribute lazima iwe namba.',
    'boolean' => 'Sehemu ya :attribute lazima iwe kweli au si kweli.',
    'email' => 'Sehemu ya :attribute lazima iwe barua pepe halali.',
    'url' => 'Sehemu ya :attribute lazima iwe anwani halali ya tovuti.',
    'min' => [
        'numeric' => 'Sehemu ya :attribute lazima iwe angalau :min.',
        'characters' => 'Sehemu ya :attribute lazima iwe na angalau herufi :min.',
        'items' => 'Sehemu ya :attribute lazima iwe na angalau vipengee :min.',
    ],
    'max' => [
        'numeric' => 'Sehemu ya :attribute lazima isizidi :max.',
        'characters' => 'Sehemu ya :attribute lazima isizidi herufi :max.',
        'items' => 'Sehemu ya :attribute lazima isizidi vipengee :max.',
    ],
    'between' => 'Sehemu ya :attribute lazima iwe kati ya :min na :max.',
    'length' => [
        'min' => 'Sehemu ya :attribute lazima iwe na angalau herufi :min.',
        'max' => 'Sehemu ya :attribute lazima isizidi herufi :max.',
    ],
    'in' => 'Sehemu ya :attribute lazima iwe mojawapo ya: :values.',
    'notIn' => 'Sehemu ya :attribute hairuhusiwi kuwa mojawapo ya: :values.',
    'regex' => 'Muundo wa sehemu ya :attribute hauruhusiwi.',
    'confirmed' => 'Uthibitisho wa sehemu ya :attribute haulingani.',
    'same' => 'Sehemu ya :attribute lazima ilingane na :other.',
    'different' => 'Sehemu ya :attribute lazima itofautiane na :other.',
    'accepted' => 'Sehemu ya :attribute lazima ikubaliwe.',
    'date' => 'Sehemu ya :attribute lazima iwe tarehe halali.',
    'before' => 'Sehemu ya :attribute lazima iwe tarehe kabla ya :date.',
    'after' => 'Sehemu ya :attribute lazima iwe tarehe baada ya :date.',
    'password' => 'Sehemu ya :attribute lazima iwe na angalau herufi :min, ikijumuisha herufi na namba.',
    'present' => 'Sehemu ya :attribute lazima iwepo.',
    'filled' => 'Sehemu ya :attribute lazima iwe na thamani.',
    'missing' => 'Sehemu ya :attribute hairuhusiwi kuwepo.',
    'ip' => 'Sehemu ya :attribute lazima iwe anwani halali ya IP.',
    'uuid' => 'Sehemu ya :attribute lazima iwe UUID halali.',
    'json' => 'Sehemu ya :attribute lazima iwe JSON halali.',
    'alpha' => 'Sehemu ya :attribute inaruhusiwa kuwa na herufi pekee.',
    'alpha_num' => 'Sehemu ya :attribute inaruhusiwa kuwa na herufi na namba pekee.',
    'alpha_dash' => 'Sehemu ya :attribute inaruhusiwa kuwa na herufi, namba, mistari na mistari-mawili pekee.',
    'size' => 'Sehemu ya :attribute lazima iwe na ukubwa wa :size.',
    'digits' => 'Sehemu ya :attribute lazima iwe namba yenye tarakimu :digits.',
    'digits_between' => 'Sehemu ya :attribute lazima iwe kati ya tarakimu :min na :max.',
    'date_format' => 'Sehemu ya :attribute lazima ilingane na muundo wa tarehe :format.',
    'before_or_equal' => 'Sehemu ya :attribute lazima iwe tarehe kabla ya au sawa na :date.',
    'after_or_equal' => 'Sehemu ya :attribute lazima iwe tarehe baada ya au sawa na :date.',
    'required_if' => 'Sehemu ya :attribute inahitajika.',
    'required_unless' => 'Sehemu ya :attribute inahitajika.',
    'required_with' => 'Sehemu ya :attribute inahitajika.',
    'required_without' => 'Sehemu ya :attribute inahitajika.',
    'file' => 'Sehemu ya :attribute lazima iwe faili lililopakiwa halali.',
    'image' => 'Sehemu ya :attribute lazima iwe picha.',
    'extensions' => 'Sehemu ya :attribute lazima iwe na mojawapo ya viambishi hivi: :values.',
    'mimes' => 'Sehemu ya :attribute lazima iwe faili la aina: :values.',
    'mimetypes' => 'Sehemu ya :attribute lazima iwe faili la aina: :values.',
    'exists' => 'Thamani iliyochaguliwa ya :attribute si halali.',
    'unique' => 'Thamani ya sehemu ya :attribute imeshatumika.',
];
