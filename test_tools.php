<?php
require_once __DIR__ . '/ai/tools.php';
echo function_exists('ai_action_run')     ? "ai_action_run OK\n"     : "ai_action_run MANQUANT\n";
echo function_exists('ai_action_proposal') ? "ai_action_proposal OK\n" : "ai_action_proposal MANQUANT\n";
echo in_array('marquer_produit_retire', ai_action_names()) ? "action retrait reconnue OK\n" : "action retrait MANQUANTE\n";