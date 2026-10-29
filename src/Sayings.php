<?php
declare(strict_types=1);

namespace Vesta;

/**
 * Beispielsprüche für den Zufallspool (alle passen auf 3×15, Zeilenumbruch = \n).
 */
final class Sayings
{
    public const ALL = [
        "CARPE DIEM {heart}",
        "HEUTE WIRD\nEIN GUTER TAG",
        "LACHEN IST\nGESUND {yellow}",
        "ZUHAUSE IST,\nWO IHR SEID {heart}",
        "ERST KAFFEE,\nDANN DIE WELT",
        "DU SCHAFFST\nDAS! {green}",
        "WASSER\nTRINKEN\nNICHT VERGESSEN",
        "GLUECK IST\nSELBSTGEMACHT",
        "TRAEUME GROSS\n{yellow}{yellow}{yellow}",
        "EINFACH MAL\nDURCHATMEN",
        "WIR SIND\nEIN TEAM {heart}",
        "KLEINE\nSCHRITTE SIND\nAUCH SCHRITTE",
        "FREITAG IST\nBALD! {orange}",
        "SEI DU SELBST.\nALLE ANDEREN\nGIBT ES SCHON",
        "HEUTE SCHON\nGELACHT?",
        "DANKE, DASS\nES EUCH GIBT",
        "MUT STEHT\nAM ANFANG",
        "ZIMMER\nAUFRAEUMEN?\n{red} NOCH NICHT",
        "PAUSE MACHEN\nIST ERLAUBT",
        "KEEP CALM\nAND HUG ON {heart}",
        "WER LIEST,\nIST KLUG",
        "GUTE IDEEN\nBRAUCHEN ZEIT",
        "FEHLER SIND\nZUM LERNEN DA",
        "SONNE IM\nHERZEN {yellow}",
        "HEUTE KOCHT\nPAPA? {orange}",
        "WOCHENENDE\nWIR KOMMEN!",
        "TEILEN MACHT\nFROH",
        "ZAEHNE\nGEPUTZT?",
        "SPIELEABEND?\n{green}{blue}{red}{yellow}",
        "ALLES WIRD\nGUT {heart}",
        "NEUGIER IST\nEINE SUPERKRAFT",
        "LIEBER BUNT\nALS GRAU\n{red}{orange}{yellow}{green}{blue}{violet}",
        "UMARMUNG\nGEFAELLIG?",
        "HAUSAUFGABEN\nFERTIG?",
        "DER WEG\nIST DAS ZIEL",
        "ES GIBT KEIN\nSCHLECHTES\nWETTER",
        "HEUTE IST\nEIN SUPERTAG",
        "SCHUHE AUS,\nFUESSE HOCH",
        "GEMEINSAM\nSIND WIR STARK",
        "GUTE NACHT\nUND SUESS\nTRAEUMEN {violet}",
    ];

    /** Englische Beispielsprüche (gleiche Regeln: max. 3 Zeilen à 15 Zeichen) */
    public const ALL_EN = [
        "CARPE DIEM {heart}",
        "TODAY IS\nA GOOD DAY",
        "SMILE, IT'S\nCONTAGIOUS {yellow}",
        "HOME IS\nWHERE YOU ARE\n{heart}",
        "COFFEE FIRST,\nTHEN THE WORLD",
        "YOU GOT\nTHIS! {green}",
        "DRINK\nSOME WATER",
        "DREAM BIG\n{yellow}{yellow}{yellow}",
        "JUST\nBREATHE",
        "WE ARE\nA TEAM {heart}",
        "SMALL STEPS\nARE STILL\nSTEPS",
        "FRIDAY IS\nCOMING! {orange}",
        "BE YOURSELF.\nEVERYONE ELSE\nIS TAKEN",
        "HAVE YOU\nLAUGHED TODAY?",
        "THANK YOU FOR\nBEING YOU",
        "BE BRAVE\n{green}{green}",
        "CLEAN YOUR\nROOM?\n{red} NOT YET",
        "IT'S OK TO\nTAKE A BREAK",
        "KEEP CALM\nAND HUG ON {heart}",
        "READERS ARE\nLEADERS",
        "GOOD IDEAS\nTAKE TIME",
        "MISTAKES HELP\nUS LEARN",
        "SUNSHINE IN\nYOUR HEART\n{yellow}",
        "WHAT'S FOR\nDINNER? {orange}",
        "WEEKEND,\nHERE WE COME!",
        "SHARING IS\nCARING",
        "BRUSHED YOUR\nTEETH?",
        "GAME NIGHT?\n{green}{blue}{red}{yellow}",
        "ALL WILL BE\nWELL {heart}",
        "CURIOSITY IS\nA SUPERPOWER",
        "LIFE IS\nCOLORFUL\n{red}{orange}{yellow}{green}{blue}{violet}",
        "HUGS\nAVAILABLE",
        "HOMEWORK\nDONE?",
        "ENJOY THE\nJOURNEY",
        "NO SUCH THING\nAS BAD\nWEATHER",
        "TODAY IS\nA GREAT DAY",
        "SHOES OFF,\nFEET UP",
        "TOGETHER\nWE ARE STRONG",
        "BE KIND\n{heart}",
        "GOOD NIGHT,\nSWEET DREAMS\n{violet}",
    ];

    /** Beispielsprüche in der aktuellen Sprache */
    public static function all(): array
    {
        return I18n::lang() === 'en' ? self::ALL_EN : self::ALL;
    }
}
