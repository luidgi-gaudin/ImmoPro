/**
 * Masque un IBAN ou un BIC en n'en laissant que la fin.
 *
 * Ces identifiants sont chiffrés au repos en base, mais cela ne protège pas ce
 * qui s'affiche à l'écran : un IBAN en clair dans un tableau se lit par-dessus
 * l'épaule, se retrouve dans une capture d'écran et dans le moindre partage de
 * fenêtre.
 *
 * Les quatre derniers caractères suffisent à reconnaître un compte parmi ceux
 * qu'on gère — c'est d'ailleurs la convention des relevés bancaires. Pour tout
 * le reste, le champ reste révélable d'un clic.
 */
export function maskBankIdentifier(value: string | null | undefined): string {
  if (!value) {
    return '-';
  }

  const trimmed = value.replace(/\s+/g, '');

  // Trop court pour être masqué utilement : on le rend illisible en entier
  // plutôt que d'en révéler la moitié.
  if (trimmed.length <= 4) {
    return '•'.repeat(trimmed.length);
  }

  return `${'•'.repeat(Math.min(trimmed.length - 4, 16))} ${trimmed.slice(-4)}`;
}
