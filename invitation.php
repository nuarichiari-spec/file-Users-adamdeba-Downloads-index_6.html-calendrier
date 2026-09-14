<?php
/**
 * La Loge Corse — réception des demandes d'invitation.
 * Reçoit le formulaire de la page d'accueil, valide les champs obligatoires
 * et transmet la demande par e-mail pour pré-validation.
 */

declare(strict_types=1);

const DEST       = 'jeromebrigato@lalogecorse.fr';
const MAX_GUESTS = 4;
const SITE       = 'https://lalogecorse.fr';

/* ------------------------------------------------------------------ */

function clean(string $v, int $max = 200): string
{
    $v = trim($v);
    $v = preg_replace('/[\r\n]+/', ' ', $v);   // anti-injection d'en-têtes
    return mb_substr($v, 0, $max);
}

function field(string $key, int $max = 200): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? clean($_POST[$key], $max) : '';
}

function fail(string $msg, int $code = 400): never
{
    http_response_code($code);
    page('Demande non envoyée', $msg, false);
    exit;
}

function page(string $title, string $msg, bool $ok): void
{
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    $c = $ok ? '#0f2b5b' : '#a32018';
    echo <<<HTML
<!doctype html>
<html lang="fr"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$t — La Loge Corse</title>
<style>
 body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
      background:#f6f1e4;color:#1c1c1c;font:16px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:24px}
 .card{background:#fff;border:1px solid #e3dccb;border-radius:16px;padding:36px;max-width:540px}
 h1{font-size:24px;margin:0 0 12px;color:$c}
 p{margin:0 0 20px;color:#555}
 a{display:inline-block;background:#54c7ee;color:#06283d;text-decoration:none;
   font-weight:700;padding:11px 20px;border-radius:999px}
</style></head><body>
<div class="card"><h1>$t</h1><p>$m</p><a href="https://lalogecorse.fr">Retour au site</a></div>
</body></html>
HTML;
}

/* ------------------------------------------------------------------ */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . SITE);
    exit;
}

// Champs partenaire
$societe = field('societe');
$referent = field('referent');
$email    = field('referent_email');
$tel      = field('referent_tel', 40);
$match    = field('match', 300);
$message  = field('message', 2000);

if ($societe === '' || $referent === '' || $email === '' || $tel === '' || $match === '') {
    fail('Merci de compléter la société, votre nom, votre e-mail, votre téléphone et le match souhaité.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('L’adresse e-mail du partenaire n’est pas valide.');
}
if (!isset($_POST['consent'])) {
    fail('Vous devez confirmer avoir l’accord de vos invités pour transmettre leurs coordonnées.');
}

// Invités : nom, prénom, e-mail et téléphone obligatoires pour chacun.
$guests = [];
foreach ($_POST as $key => $_) {
    if (preg_match('/^invite(\d+)_nom$/', (string) $key, $m)) {
        $i      = $m[1];
        $nom    = field("invite{$i}_nom");
        $prenom = field("invite{$i}_prenom");
        $gmail  = field("invite{$i}_email");
        $gtel   = field("invite{$i}_tel", 40);

        if ($nom === '' && $prenom === '' && $gmail === '' && $gtel === '') {
            continue; // bloc vide ignoré
        }
        if ($nom === '' || $prenom === '' || $gmail === '' || $gtel === '') {
            fail('Chaque invité doit avoir un nom, un prénom, un e-mail et un téléphone.');
        }
        if (!filter_var($gmail, FILTER_VALIDATE_EMAIL)) {
            fail("L’adresse e-mail de l’invité « $prenom $nom » n’est pas valide.");
        }
        $guests[] = compact('nom', 'prenom', 'gmail', 'gtel');
    }
}

if (!$guests) {
    fail('Merci de renseigner au moins un invité.');
}
if (count($guests) > MAX_GUESTS) {
    fail('Vous pouvez inviter au maximum ' . MAX_GUESTS . ' personnes.');
}

/* ------------------------------------------------------------------ */

$lines   = [];
$lines[] = 'DEMANDE D’INVITATION — LA LOGE CORSE';
$lines[] = str_repeat('=', 46);
$lines[] = '';
$lines[] = 'Match       : ' . $match;
$lines[] = '';
$lines[] = 'PARTENAIRE';
$lines[] = '  Société   : ' . $societe;
$lines[] = '  Référent  : ' . $referent;
$lines[] = '  E-mail    : ' . $email;
$lines[] = '  Téléphone : ' . $tel;
$lines[] = '';
$lines[] = 'INVITÉS (' . count($guests) . '/' . MAX_GUESTS . ')';
foreach ($guests as $k => $g) {
    $lines[] = sprintf('  %d. %s %s', $k + 1, $g['prenom'], $g['nom']);
    $lines[] = '     E-mail    : ' . $g['gmail'];
    $lines[] = '     Téléphone : ' . $g['gtel'];
}
if ($message !== '') {
    $lines[] = '';
    $lines[] = 'MESSAGE';
    $lines[] = '  ' . $message;
}
$lines[] = '';
$lines[] = str_repeat('-', 46);
$lines[] = 'Reçue le ' . date('d/m/Y à H:i');
$lines[] = 'Demande à pré-valider.';

$body    = implode("\n", $lines);
$subject = 'Invitation Loge Corse — ' . $societe . ' (' . count($guests) . ' invité'
         . (count($guests) > 1 ? 's' : '') . ')';

$headers = implode("\r\n", [
    'From: La Loge Corse <no-reply@lalogecorse.fr>',
    'Reply-To: ' . $referent . ' <' . $email . '>',
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP/' . phpversion(),
]);

$sent = @mail(DEST, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);

if (!$sent) {
    fail('L’envoi a échoué. Écrivez-nous directement à ' . DEST . ' — nous traiterons votre demande.', 500);
}

// Accusé de réception au partenaire (sans bloquer en cas d'échec).
@mail(
    $email,
    '=?UTF-8?B?' . base64_encode('Votre demande d’invitation — La Loge Corse') . '?=',
    "Bonjour,\n\nNous avons bien reçu votre demande pour :\n" . $match .
    "\n\nElle sera pré-validée puis confirmée par e-mail.\n\nRécapitulatif :\n\n" . $body .
    "\n\nÀ bientôt à Jean-Bouin.\nLa Loge Corse",
    $headers
);

page(
    'Demande envoyée',
    'Merci ' . $referent . ' — votre demande pour ' . $match . ' a bien été transmise. '
    . 'Vous recevrez un e-mail de confirmation après pré-validation.',
    true
);
