import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'api.dart';
import 'theme.dart';
import 'screens/login.dart';
import 'screens/dashboard.dart';
import 'screens/sales_quick.dart';
import 'screens/settings.dart';
import 'screens/sales_quick_entry.dart';
import 'screens/splash.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await Api.init();
  runApp(const LivreurApp());
}

class LivreurApp extends StatelessWidget {
  const LivreurApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Hillembalage Livreur',
      theme: AppTheme.theme(),
      locale: const Locale('fr'),
      supportedLocales: const [Locale('fr'), Locale('en')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      initialRoute: '/splash',
      routes: {
        '/splash': (_) => const SplashScreen(),
        '/login': (_) => const LoginScreen(),
        '/dashboard': (_) => const DashboardScreen(),
        '/sales_quick_entry': (_) => const SalesQuickEntryScreen(),
        '/sales_quick': (_) => const SalesQuickScreen(),
        '/settings': (_) => const SettingsScreen(),
      },
    );
  }
}
