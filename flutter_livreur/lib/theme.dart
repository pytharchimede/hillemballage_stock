import 'package:flutter/material.dart';

class AppTheme {
  // Charte Hillembalage : Jaune + Gris (mode clair)
  static const Color primary = Color(0xFFFFC107); // Jaune (Amber 500)
  static const Color secondary = Color(0xFF616161); // Gris (Grey 700)
  static const Color background = Color(0xFFF5F5F5); // Gris très clair

  static ThemeData theme() {
    final colorScheme = ColorScheme.fromSeed(
      seedColor: primary,
      primary: primary,
      secondary: secondary,
      brightness: Brightness.light,
    );
    return ThemeData(
      colorScheme: colorScheme,
      useMaterial3: true,
      scaffoldBackgroundColor: background,
      appBarTheme: const AppBarTheme(
        backgroundColor: primary,
        // Texte/icone foncés pour contraste sur jaune
        foregroundColor: Colors.black,
      ),
      inputDecorationTheme: const InputDecorationTheme(
        border: OutlineInputBorder(),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: primary,
          foregroundColor: Colors.black,
        ),
      ),
    );
  }
}
