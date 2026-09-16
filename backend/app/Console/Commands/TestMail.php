<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envoie un message d'essai et dit précisément ce qui cloche.
 *
 * Diagnostiquer une panne d'envoi à l'aveugle est pénible : le message part
 * dans une file, l'erreur atterrit dans un journal, et rien ne distingue « mot
 * de passe refusé » de « aucun ouvrier ne traite la file ». Cette commande
 * court-circuite tout — elle envoie tout de suite, en direct, et rapporte
 * l'échec tel qu'il vient du serveur de messagerie.
 */
class TestMail extends Command
{
    protected $signature = 'immopro:mail-test
                            {destinataire : Adresse à laquelle envoyer le message d\'essai}';

    protected $description = 'Vérifie la configuration d\'envoi de courriels';

    public function handle(): int
    {
        $to = (string) $this->argument('destinataire');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("« {$to} » n'est pas une adresse e-mail valide.");

            return self::FAILURE;
        }

        $this->reportConfiguration();

        if (config('mail.default') === 'log') {
            $this->warn('MAIL_MAILER vaut « log » : aucun message ne quittera le serveur.');
            $this->line('  Les courriels sont écrits dans storage/logs/laravel.log.');

            return self::FAILURE;
        }

        $this->line("Envoi d'un message d'essai à {$to}…");

        try {
            Mail::raw(
                "Ceci est un message d'essai envoyé par ImmoPro.\n\n"
                    ."Si vous le lisez, la configuration d'envoi est correcte : les codes de\n"
                    .'vérification et les relances de loyer partiront par le même chemin.',
                fn ($message) => $message->to($to)->subject('ImmoPro — essai de configuration')
            );
        } catch (Throwable $exception) {
            $this->error('Échec de l\'envoi.');
            $this->newLine();
            $this->line($exception->getMessage());
            $this->newLine();

            $this->explain($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Message envoyé à {$to}.");
        $this->line('Vérifiez la boîte de réception, et le dossier « indésirables ».');

        return self::SUCCESS;
    }

    private function reportConfiguration(): void
    {
        $mailer = (string) config('mail.default');
        $config = config("mail.mailers.{$mailer}", []);

        /*
         * Chaque clé est lue avec un repli : le transport « log » n'a ni hôte
         * ni identifiant, et les lire directement fait échouer la commande de
         * diagnostic — précisément dans le cas où l'on s'en sert le plus.
         */
        $show = fn (string $key) => filled($config[$key] ?? null) ? (string) $config[$key] : '—';

        $this->table(['Réglage', 'Valeur'], [
            ['MAIL_MAILER', $mailer],
            ['Hôte', $show('host')],
            ['Port', $show('port')],
            ['Chiffrement', $show('scheme') === '—' ? 'automatique' : $show('scheme')],
            ['Identifiant', $show('username')],
            // Jamais la valeur du mot de passe : cette commande se lance
            // souvent devant quelqu'un, et une capture d'écran circule vite.
            ['Mot de passe', filled($config['password'] ?? null) ? 'renseigné' : '— (vide)'],
            ['Expéditeur', (string) config('mail.from.address')],
        ]);

        $this->newLine();
    }

    /**
     * Traduit les messages d'erreur les plus courants.
     *
     * Ceux de Gmail sont particulièrement trompeurs : « Username and Password
     * not accepted » laisse croire à une faute de frappe alors qu'il s'agit
     * neuf fois sur dix d'un mot de passe de compte employé à la place d'un mot
     * de passe d'application.
     */
    private function explain(string $message): void
    {
        $hints = [
            'scheme is not supported' => 'MAIL_SCHEME n\'accepte que « smtp » ou « smtps ». '
                .'La valeur « tls », que recommandent la plupart des guides Gmail, est refusée : '
                .'le chiffrement du port 587 se négocie tout seul sous « smtp ». '
                .'Port 587 → smtp, port 465 → smtps.',

            'Username and Password not accepted' => 'Gmail refuse le mot de passe du compte pour SMTP. '
                ."Il faut un mot de passe d'application de seize caractères, créé depuis "
                .'la sécurité du compte Google, une fois la validation en deux étapes activée.',

            'Application-specific password required' => 'La validation en deux étapes est active : '
                ."seul un mot de passe d'application est accepté.",

            'Connection could not be established' => 'Le serveur SMTP est injoignable. Vérifiez '
                ."l'hôte, le port, et qu'aucun pare-feu ne bloque la sortie.",

            'certificate verify failed' => 'La chaîne de certificats du système est incomplète. '
                .'Sur macOS avec PHP installé par Homebrew, cela vient souvent d\'un fichier '
                .'de certificats non renseigné dans php.ini.',

            'Expected response code "250"' => "L'expéditeur est refusé. Avec Gmail, "
                .'MAIL_FROM_ADDRESS doit être exactement l\'adresse du compte employé pour '
                .'la connexion.',
        ];

        foreach ($hints as $needle => $hint) {
            if (str_contains($message, $needle)) {
                $this->warn('Piste : '.$hint);

                return;
            }
        }
    }
}
