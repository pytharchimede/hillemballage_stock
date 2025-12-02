import 'package:flutter/material.dart';

class AppTheme {
  // Couleurs proches de l'app web (à ajuster si besoin)
  static const Color primary = Color(0xFF1E88E5); // bleu
  static const Color secondary = Color(0xFF43A047); // vert
  static const Color background = Color(0xFFF7F7F7);

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
        foregroundColor: Colors.white,
      ),
      inputDecorationTheme: const InputDecorationTheme(
        border: OutlineInputBorder(),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: primary,
          foregroundColor: Colors.white,
        ),
      ),
    );
  }
}
