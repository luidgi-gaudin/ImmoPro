<?php

namespace App\Enums;

/**
 * Étiquette « gaz à effet de serre » du diagnostic de performance énergétique.
 *
 * Le DPE porte deux étiquettes depuis la réforme de 2021 : la consommation
 * d'énergie primaire et les émissions de GES. Elles ne coïncident pas — un
 * logement chauffé à l'électricité peut être classé E en énergie et B en GES —
 * et l'interdiction de location se déduit de l'étiquette énergie seule. Les
 * confondre en un champ unique revient à perdre la moitié du diagnostic.
 */
enum Ges: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
    case E = 'E';
    case F = 'F';
    case G = 'G';
}
