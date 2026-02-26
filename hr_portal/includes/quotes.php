<?php
// File: includes/quotes.php
// Collection of motivational quotes for MAKSIM PORTAL

$motivational_quotes = [
    // Teamwork & Collaboration
    "Work Together - Grow Together.",
    "Alone we can do so little; together we can do so much.",
    "Teamwork makes the dream work.",
    "Great things in business are never done by one person – they're done by a team.",
    "Coming together is a beginning, staying together is progress, and working together is success.",
    
    // Success & Excellence
    "Success is not final, failure is not fatal: it's the courage to continue that counts.",
    "The only way to do great work is to love what you do.",
    "Excellence is not a skill, it's an attitude.",
    "Strive not to be a success, but rather to be of value.",
    "The secret of getting ahead is getting started.",
    
    // Growth & Learning
    "Every expert was once a beginner.",
    "Growth and comfort do not coexist.",
    "The beautiful thing about learning is that no one can take it away from you.",
    "Your dedication today builds MAKSIM's success tomorrow.",
    "Small daily improvements lead to stunning results.",
    
    // Leadership & Inspiration
    "A good leader inspires people to have confidence in the leader; a great leader inspires people to have confidence in themselves.",
    "Leadership is not about being in charge. It's about taking care of those in your charge.",
    "The greatest leader is not necessarily the one who does the greatest things, but the one who gets people to do the greatest things.",
    
    // Work Ethic
    "The harder you work for something, the greater you'll feel when you achieve it.",
    "Don't watch the clock; do what it does. Keep going.",
    "Your work is going to fill a large part of your life, and the only way to be truly satisfied is to do what you believe is great work.",
    
    // Positivity
    "Every day may not be good, but there's something good in every day.",
    "Stay positive, work hard, make it happen.",
    "Positive thinking will let you do everything better than negative thinking will.",
    
    // Perseverance
    "It always seems impossible until it's done.",
    "The only limit to our realization of tomorrow is our doubts of today.",
    "Perseverance is not a long race; it is many short races one after the other.",
    
    // Innovation
    "Innovation distinguishes between a leader and a follower.",
    "The best way to predict the future is to create it.",
    
    // Balance
    "Take care of your employees, and they'll take care of your business.",
    "Rest when you're weary. Refresh and renew yourself. Your team needs you at your best.",
    
    // MAKSIM Specific
    "Making a difference at MAKSIM, one day at a time.",
    "Your contribution today shapes MAKSIM's tomorrow.",
    "Proud to be part of the MAKSIM family.",
    "Together we achieve more at MAKSIM."
];

// Function to get a random quote
function getRandomQuote() {
    global $motivational_quotes;
    $random_index = array_rand($motivational_quotes);
    return $motivational_quotes[$random_index];
}

// Function to get a quote by category (optional enhancement)
function getQuoteByCategory($category = 'all') {
    global $motivational_quotes;
    // You can expand this later to have categorized quotes
    return getRandomQuote();
}
?>