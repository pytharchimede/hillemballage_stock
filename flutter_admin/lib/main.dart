import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'api.dart';
import 'theme.dart';
import 'screens/login.dart';
import 'screens/splash.dart';
import 'screens/dashboard.dart';
import 'screens/settings.dart';
import 'screens/products.dart';
import 'screens/product_detail.dart';
import 'screens/product_form.dart';
import 'screens/clients.dart';
import 'screens/client_form.dart';
import 'screens/depots.dart';
import 'screens/sales.dart';
import 'screens/rounds.dart';
import 'screens/reports.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await Api.init();
  runApp(const AdminApp());
}

class AdminApp extends StatelessWidget {
  const AdminApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Hill-Admin',
      theme: AdminTheme.theme(),
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
        '/settings': (_) => const SettingsScreen(),
        '/products': (_) => const ProductsScreen(),
        '/product': (_) => const ProductDetailScreen(),
        '/product_form': (_) => const ProductFormScreen(),
        '/clients': (_) => const ClientsScreen(),
        '/client_form': (_) => const ClientFormScreen(),
        '/depots': (_) => const DepotsScreen(),
        '/sales': (_) => const SalesScreen(),
        '/rounds': (_) => const RoundsScreen(),
        '/reports': (_) => const ReportsScreen(),
      },
    );
  }
}
