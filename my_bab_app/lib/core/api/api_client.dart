import 'package:dio/dio.dart';
import 'package:shared_preferences/shared_preferences.dart';

class ApiClient {
  static const String baseUrl = 'http://127.0.0.1:8000/api/v1';
  late final Dio dio;

  ApiClient() {
    dio = Dio(
      BaseOptions(
        baseUrl: baseUrl,
        connectTimeout: const Duration(seconds: 10),
        receiveTimeout: const Duration(seconds: 10),
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
      ),
    );

    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final prefs = await SharedPreferences.getInstance();
          final token = prefs.getString('auth_token');
          final tenantId = prefs.getInt('tenant_id');

          if (token != null && token.isNotEmpty) {
            options.headers['Authorization'] = 'Bearer $token';
          }
          if (tenantId != null) {
            options.headers['X-Tenant-ID'] = tenantId.toString();
          }

          return handler.next(options);
        },
        onError: (DioException e, handler) {
          String message = 'An unexpected error occurred.';
          if (e.response?.data != null && e.response?.data['message'] != null) {
            message = e.response?.data['message'];
          }
          return handler.next(
            DioException(
              requestOptions: e.requestOptions,
              response: e.response,
              error: message,
            ),
          );
        },
      ),
    );
  }
}
