<?php
//COPY THIS FILE TO options.inc.php and make changes there to activate your printable action booklet

$options=array(
    'fpdfpath'  => 'fpdf/', // path from the main gtd-php directory to the FPDF installation directory
    'papersize' => 'letter',   // paper size - A4, letter, etc
    'fontname'  => 'Arial',    // typeface name
    'fontsize'  => 8,          // typeface size in points
    'nextonly'  => true        // true to show only *next* actions
);
?>
