<?php
// ========================================
// 2.php: 영화사 목록 및 제작 영화 수 표시 (메인 페이지)
// ========================================
// 목적: 1편 이상 영화를 제작한 스튜디오 목록을 표시하고,
//       각 스튜디오 이름과 제작 영화 수에 하이퍼링크 추가
//       - 스튜디오 이름 클릭 → 상세 정보 페이지 (studio_detail.php)
//       - 제작 영화 수 클릭 → 영화 목록 페이지 (movies_list.php)
// ========================================

// ===== 한글 인코딩 설정 (선택사항) =====
// Oracle 데이터베이스의 한글 데이터를 올바르게 처리하기 위한 환경 변수
//putenv("NLS_LANG=KOREAN_KOREA.AL32UTF8");

// ===== 에러 처리 함수 =====
/**
 * Oracle 에러 메시지를 출력하고 스크립트 종료
 * 
 * @param resource|null $id - OCI 리소스 핸들 (connection 또는 statement)
 *                           null인 경우 전역 에러 확인
 */
function p_error($id=null){
    if($id == null) 
        $e = oci_error();      // 전역 에러 (주로 연결 실패)
    else 
        $e = oci_error($id);   // 특정 리소스의 에러 (파싱, 실행 실패 등)
    
    // HTML 특수문자를 엔티티로 변환하여 안전하게 출력
    print htmlentities($e['message']);
    exit();  // 스크립트 즉시 종료
}

// ===== 데이터베이스 연결 =====
// Oracle 데이터베이스에 연결
$conn = oci_connect("db2021663028","db88602924", "localhost/lecture");

// 연결 실패 시 에러 처리
if(!$conn) p_error();

// ===== 스튜디오별 제작 영화 수 조회 쿼리 =====
$stmt = oci_parse($conn,
    // SELECT 절: 스튜디오 이름과 제작 영화 수
    "select s.name, count(m.title) cnt ".
    // FROM 절: studio와 movie 테이블 조인
    "from studio s, movie m ".
    // WHERE 절: 스튜디오 이름으로 조인
    "where s.name = m.studioname ".
    // GROUP BY 절: 스튜디오별로 그룹화하여 집계
    "group by s.name ".
    // HAVING 절: 1편 이상 제작한 스튜디오만 표시
    // (영화를 제작하지 않은 스튜디오는 제외)
    "having count(m.title) >= 1 ".
    // ORDER BY 절: 스튜디오 이름 순으로 정렬
    "order by s.name");

// 파싱 실패 시 에러 처리
if(!$stmt) p_error($conn);

// ===== 쿼리 실행 =====
$r = oci_execute($stmt);

// 실행 실패 시 에러 처리
if(!$r) p_error($conn);

// ===== HTML 테이블 헤더 출력 =====
// 핑크색 배경, 1픽셀 테두리, 3픽셀 셀 간격의 테이블 시작
print "<TABLE bgcolor='pink' border=1 cellspacing=3>\n";

// 빨간색 배경의 헤더 행 (중앙 정렬)
print "<TR bgcolor='red' align=center><TH>영화사<TH>제작한 영화수</TR>\n";

// ===== 메인 루프: 각 스튜디오 정보 출력 =====
while ($row = oci_fetch_array($stmt)) {
    // 각 컬럼 값을 변수에 저장
    $name = $row[0];    // 스튜디오 이름
    $cnt = $row[1];     // 제작한 영화 수
    
    // ===== URL 인코딩 =====
    // 스튜디오 이름을 URL 파라미터로 전달하기 위해 인코딩
    // 예: "Universal Studios" → "Universal+Studios"
    // 목적: 
    //   1. 공백, 특수문자를 URL에 안전하게 포함
    //   2. 한글 이름도 올바르게 전달
    $encoded_name = urlencode($name);
    
    // ===== 데이터 행 출력 (하이퍼링크 포함) =====
    print "<TR>";
    
    // 첫 번째 셀: 스튜디오 이름 (클릭 시 상세 정보 페이지로 이동)
    // GET 방식으로 스튜디오 이름 전달
    print "<TD><a href='studio_detail.php?name=$encoded_name'>$name</a>";
    
    // 두 번째 셀: 제작 영화 수 (클릭 시 영화 목록 페이지로 이동)
    // GET 방식으로 스튜디오 이름 전달
    print "<TD><a href='movies_list.php?name=$encoded_name'>$cnt</a>";
    
    print "</TR>\n";
}

// ===== 테이블 종료 =====
print "</TABLE>\n";

// ===== 리소스 정리 =====
// 쿼리 리소스 해제
oci_free_statement($stmt);

// 데이터베이스 연결 종료
oci_close($conn);

?>

<!-- 
========================================
페이지 출력 예시:
========================================

영화사 목록 테이블:
+------------------+------------------+
| 영화사           | 제작한 영화수    |
+------------------+------------------+
| Disney           | 15 (링크)        |
| Paramount        | 8 (링크)         |
| Universal        | 12 (링크)        |
| Warner Bros      | 20 (링크)        |
+------------------+------------------+

각 항목을 클릭하면:
- "Disney" 클릭 → studio_detail.php?name=Disney (상세 정보)
- "15" 클릭 → movies_list.php?name=Disney (영화 목록)

========================================
페이지 네비게이션 구조:
========================================

[2.php - 메인 페이지]
    ↓
    ├─→ [studio_detail.php] - 스튜디오 상세 정보
    │     (스튜디오 이름 클릭 시)
    │
    └─→ [movies_list.php] - 제작 영화 목록
          (제작 영화 수 클릭 시)

========================================
주요 기능:
========================================
1. 영화를 제작한 모든 스튜디오 조회 (HAVING 절 사용)
2. 스튜디오별 제작 영화 수 집계 (GROUP BY, COUNT)
3. 인터랙티브 네비게이션 (하이퍼링크)
4. URL 파라미터 안전한 전달 (urlencode)
5. 알파벳 순 정렬 (ORDER BY name)

========================================
URL 파라미터 전달 방식:
========================================
- GET 방식 사용
- 형식: page.php?name=스튜디오이름
- urlencode() 사용 이유:
  * 공백을 + 또는 %20으로 변환
  * 특수문자를 %XX 형식으로 인코딩
  * 한글을 UTF-8 인코딩으로 변환
  * 예: "Warner Bros" → "Warner+Bros"
  * 예: "디즈니" → "%EB%94%94%EC%A6%88%EB%8B%88"

========================================
SQL 집계 함수 사용:
========================================
- COUNT(m.title): 각 스튜디오가 제작한 영화 수
- GROUP BY s.name: 스튜디오별로 그룹화
- HAVING count(...) >= 1: 최소 1편 이상 제작한 스튜디오만

========================================
-->
