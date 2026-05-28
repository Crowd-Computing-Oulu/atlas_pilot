<?php
// Synthetic values for the researcher preview (?fill=1). Static, no DB writes.
// Edit the values here to change what the auto-filled preview shows.
function synthetic_preview(): array {
    return [
        // PSS-4 raw answers (0-4 scale, before reverse scoring of q2/q3).
        'pss4' => [
            'pss4_q1' => 2, // Sometimes
            'pss4_q2' => 3, // Fairly often
            'pss4_q3' => 3, // Fairly often
            'pss4_q4' => 1, // Almost never
        ],
        // GAD-2 raw answers (0-3 scale, direct-scored).
        'gad2' => [
            'gad2_q1' => 2, // More than half the days
            'gad2_q2' => 1, // Several days
        ],
        'description' => "When I feel stressed or anxious I do 4-7-8 breathing: I breathe in through my nose for 4 seconds, hold for 7, and exhale slowly through my mouth for 8. I repeat this for about five cycles, sitting alone in my room with the lights dimmed, usually a couple of times during a stressful day.",
        'refinement_response' => "I usually do it for about five minutes, two or three times a day, and I use a meditation app to time the breaths.",
        'semantic_fidelity' => 6,
        'self_distortion' => 2,
        'fidelity_feedback' => "The summary captured my practice well.",
        'context_text' => "After a stressful work day, or when I cannot fall asleep at night.",
        'outcome_text' => "My heart rate slows down, my mind feels clearer, and I can usually get back to what I was doing.",
        'willingness' => 6,
        'attention_check' => 1, // "Strongly disagree" = pass for the IMC item.
    ];
}
