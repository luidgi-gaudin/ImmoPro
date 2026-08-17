export interface FaqEntry {
  /** Ancre stable de la question : sert de cible aux liens internes (/faq#baux). */
  id: string;
  question: string;
  /** Texte brut : la même chaîne alimente l'affichage et le balisage FAQPage. */
  answer: string;
}

/**
 * Source unique : l'affichage et le JSON-LD `FAQPage` lisent le même tableau.
 * Google exige que le balisage reprenne mot pour mot ce que voit le visiteur.
 */
export const FAQ_ENTRIES: FaqEntry[] = [
  {
    id: 'public',
    question: 'À qui s’adresse ImmoPro ?',
    answer:
      'ImmoPro s’adresse aux professionnels et aux bailleurs qui gèrent plusieurs biens : ' +
      'administrateurs de biens, agences de gestion locative, sociétés civiles immobilières et ' +
      'investisseurs particuliers multipropriétaires. L’application est conçue pour un parc de ' +
      'quelques lots comme pour plusieurs centaines, regroupés en portefeuilles.',
  },
  {
    id: 'baux',
    question: 'Quels types de baux puis-je établir ?',
    answer:
      'ImmoPro couvre les baux d’habitation régis par la loi du 6 juillet 1989 : location nue, ' +
      'location meublée, bail étudiant et bail mobilité. Les durées légales et le plafond du ' +
      'dépôt de garantie propres à chaque type sont vérifiés à la saisie, ce qui évite de signer ' +
      'un bail non conforme.',
  },
  {
    id: 'quittances',
    question: 'Les quittances de loyer générées sont-elles valables ?',
    answer:
      'Oui. Chaque quittance reprend les mentions exigées par l’article 21 de la loi du ' +
      '6 juillet 1989 : identité du bailleur et du locataire, adresse du logement, période ' +
      'couverte, détail du loyer et des charges. Elle s’imprime ou s’enregistre en PDF depuis le ' +
      'navigateur, et n’est délivrée qu’une fois le paiement enregistré comme reçu.',
  },
  {
    id: 'impayes',
    question: 'Comment suivre les loyers impayés ?',
    answer:
      'Chaque bail génère un échéancier mensuel. Le tableau de bord affiche les échéances à ' +
      'venir, celles qui sont réglées et celles en retard, et le centre d’alertes signale les ' +
      'retards de paiement ainsi que les baux qui approchent de leur échéance.',
  },
  {
    id: 'securite',
    question: 'Mes données sont-elles en sécurité ?',
    answer:
      'Les échanges avec l’application sont chiffrés, les sessions sont protégées par jeton et ' +
      'la double authentification (TOTP) peut être activée sur chaque compte, avec des codes de ' +
      'récupération d’urgence. Les données de vos locataires ne sont ni revendues ni utilisées à ' +
      'des fins publicitaires.',
  },
  {
    id: 'rgpd',
    question: 'ImmoPro est-il conforme au RGPD ?',
    answer:
      'Oui. Vous restez responsable de traitement pour les données de vos locataires et ImmoPro ' +
      'intervient comme sous-traitant. Aucun traceur publicitaire n’est déposé, la mesure ' +
      'd’audience est facultative et soumise à votre consentement, et vous pouvez exporter ou ' +
      'supprimer les données d’un locataire depuis sa fiche.',
  },
  {
    id: 'portefeuilles',
    question: 'Puis-je gérer plusieurs portefeuilles distincts ?',
    answer:
      'Oui. Un portefeuille regroupe les biens d’une même entité — une SCI, un mandant, un ' +
      'immeuble. Chacun dispose de sa vue d’ensemble, de ses statistiques d’occupation et de son ' +
      'loyer cumulé, ce qui permet de rendre compte séparément à chaque propriétaire.',
  },
  {
    id: 'dpe',
    question: 'Le DPE de mes logements est-il suivi ?',
    answer:
      'La fiche de chaque bien enregistre son diagnostic de performance énergétique aux côtés de ' +
      'sa surface et de son nombre de pièces. Vous repérez ainsi d’un coup d’œil les logements ' +
      'classés F ou G, concernés par le calendrier d’interdiction progressive à la location.',
  },
];

/** Balisage `FAQPage` correspondant, à publier sur la page qui affiche ces réponses. */
export function buildFaqJsonLd(url: string): Record<string, unknown> {
  return {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    '@id': `${url}#faq`,
    mainEntity: FAQ_ENTRIES.map((entry) => ({
      '@type': 'Question',
      '@id': `${url}#${entry.id}`,
      name: entry.question,
      acceptedAnswer: {
        '@type': 'Answer',
        text: entry.answer,
      },
    })),
  };
}
