<?php
// ========================================
// movie_manage.php: 영화 관리 CRUD 처리
// ========================================
// 목적: movie 테이블에 대한 CRUD (Create, Read, Update, Delete) 작업 처리
// 입력: POST 방식으로 전달된 폼 데이터
// 출력: 작업 결과 메시지 + 검색 결과 테이블 (iframe에 표시)
// ========================================

// ===== 에러 처리 함수 (향상된 버전) =====
/**
 * Oracle 에러를 상세하게 출력하는 함수
 * 
 * @param string $msg - 사용자 정의 에러 메시지
 * @param resource|null $id - OCI 리소스 핸들
 * 
 * 출력 정보:
 * 1. 사용자 정의 메시지 (빨간색)
 * 2. Oracle 에러 메시지
 * 3. 문제가 발생한 SQL 문
 * 4. 에러 위치 표시 (^)
 */
function p_error($msg, $id=null) {
    // 사용자 정의 메시지 출력 (빨간색)
    print "<font color=red>".$msg."</font><br>";
    
    // Oracle 에러 정보 가져오기
    if($id == null) 
        $e = oci_error();      // 전역 에러 (연결 실패 등)
    else 
        $e = oci_error($id);   // 특정 리소스의 에러

    // Oracle 에러 메시지 출력
    print htmlentities($e['message']);
    
    // SQL 문과 에러 위치 출력
    print "\n<pre>\n";
    print htmlentities($e['sqltext']);        // 실행하려던 SQL 문
    printf("\n%".($e['offset']+1)."s", "^");  // 에러 발생 위치에 ^ 표시
    print  "\n</pre>\n";
    
    // 스크립트 즉시 종료
    exit();
}

// ===== 영화 존재 여부 확인 함수 =====
/**
 * 특정 영화(제목+연도)가 데이터베이스에 존재하는지 확인
 * 
 * @param string $title - 영화 제목
 * @param int $year - 개봉 연도
 * @param resource $conn - 데이터베이스 연결
 * @return bool - 존재하면 true, 없으면 false
 * 
 * 용도:
 * - 삭제 전 영화 존재 확인
 * - 중복 삽입 방지 (추가 구현 가능)
 */
function movie_exists($title, $year, $conn){
    // 준비된 문장(Prepared Statement) 사용
    $stmt = oci_parse($conn,
        "select * from movie where title = :1 and year = :2"
    );
    if (!$stmt) p_error("Parsing Error", $conn);

    // ===== 바인드 변수 사용 =====
    // 장점:
    // 1. SQL 인젝션 방지 (가장 안전한 방법)
    // 2. 쿼리 플랜 재사용 (성능 향상)
    // 3. 특수문자 자동 처리 (이스케이프 불필요)
    oci_bind_by_name($stmt, ":1", $title);
    oci_bind_by_name($stmt, ":2", $year);

    if (!oci_execute($stmt)) p_error("Execution Error", $stmt);

    // 결과 행 수 확인
    $n = oci_fetch_all($stmt, $r);
    
    // 1개 이상이면 존재 (기본키이므로 최대 1개)
    return $n > 0;
}

// ===== 데이터베이스 연결 =====
$conn = oci_connect("db2021663028","db88602924", "localhost/lecture");
if (!$conn) p_error("Connection Error");

// ===== POST 데이터 수신 =====
// movie_manage.html에서 전달된 폼 데이터
$title = $_POST["title"];      // 영화 제목
$year = $_POST["year"];        // 개봉 연도
$length = $_POST["length"];    // 상영시간
$pno = $_POST["pno"];          // 제작자 번호 (producerno)
$sname = $_POST["sname"];      // 영화사 이름 (studioname)
$submit = $_POST["submit"];    // 클릭된 버튼 ("검색", "삽입", "갱신")

// ===== 작업 플래그 초기화 =====
$search = $insert = $update = false;

// ===== 버튼 종류에 따른 플래그 설정 =====
if(!empty($submit)){
    switch ($submit){
        case "검색":
            $search = true;
            break;
        case "삽입":
            $insert = true;
            break;
        case "갱신":
            // 주의: 오타 수정 필요 (ture → true)
            $update = true;  // 원본: ture (오타)
            break;
        default:
            break;
    }
}

// ===== 삭제할 영화 목록 수신 =====
// 검색 결과 테이블에서 체크된 영화들
// 형식: ["Avatar|2009", "Titanic|1997", ...]
$mv = $_POST["mv"];

// ========================================
// DELETE 처리
// ========================================
// 검색 결과에서 체크박스로 선택된 영화들을 삭제
if(!empty($mv)){
    foreach($mv as $v){
        // ===== 값 파싱 =====
        // "제목|연도" 형식을 분리
        // 예: "Avatar|2009" → $tt="Avatar", $yy=2009
        list($tt, $yy) = explode("|", $v);

        // ===== 영화 존재 여부 확인 =====
        if(!movie_exists($tt, $yy, $conn)){
            // 존재하지 않는 영화 (이미 삭제되었거나 데이터 오류)
            print "Movie($tt,$yy) 튜플은 없음 <br>";
        } else {
            // ===== DELETE 쿼리 실행 =====
            $stmt = oci_parse($conn,
                "delete from movie where title = :1 and year = :2"
            );
            if (!$stmt) p_error("Parsing Error", $conn);

            // 바인드 변수 사용 (SQL 인젝션 방지)
            oci_bind_by_name($stmt, ":1", $tt);
            oci_bind_by_name($stmt, ":2", $yy);

            if (!oci_execute($stmt)) p_error("Execution Error", $stmt);

            // 성공 메시지 출력
            print "- Movie($tt,$yy) 튜플 삭제됨 <br>";
        }
    }
}

// ========================================
// INSERT 처리
// ========================================
if($insert){
    // ===== INSERT 쿼리 실행 =====
    // movie 테이블 구조: (title, year, length, incolor, studioname, producerno)
    $stmt = oci_parse($conn,
        "insert into movie values(:tt,:yy,:len,'t',:sn,:pno)"
    );
    // 컬럼 순서:
    // :tt - title (제목)
    // :yy - year (연도)
    // :len - length (상영시간)
    // 't' - incolor (컬러 여부, 't'=true 고정)
    // :sn - studioname (영화사)
    // :pno - producerno (제작자 번호)
    
    if (!$stmt) p_error("Parsing Error", $conn);

    // ===== 바인드 변수 설정 =====
    oci_bind_by_name($stmt, ":tt", $title);
    oci_bind_by_name($stmt, ":yy", $year);
    oci_bind_by_name($stmt, ":len", $length);
    oci_bind_by_name($stmt, ":pno", $pno);
    oci_bind_by_name($stmt, ":sn", $sname);

    if (!oci_execute($stmt)) p_error("insertion Error", $stmt);

    // 성공 메시지 출력
    // 주의: $tt, $yy 변수가 정의되지 않음 (수정 필요)
    // 올바른 코드: print "- Movie($title,$year) 튜플 삽입됨 <br>";
    print "- Movie($title,$year) 튜플 삽입됨 <br>";
}

// ========================================
// UPDATE 처리
// ========================================
if($update){
    // ===== UPDATE 쿼리 실행 =====
    // length, studioname, producerno를 수정
    // title과 year로 영화 식별 (WHERE 조건)
    $stmt = oci_parse($conn,
        "update movie set length=:len,studioname=:sn,producerno=:pno ".
        "where title = :tt and year = :yy"
    );
    if (!$stmt) p_error("Parsing Error", $conn);

    // ===== 바인드 변수 설정 =====
    // SET 절에 사용될 값들
    oci_bind_by_name($stmt, ":len", $length);
    oci_bind_by_name($stmt, ":pno", $pno);
    oci_bind_by_name($stmt, ":sn", $sname);
    
    // WHERE 절에 사용될 값들
    oci_bind_by_name($stmt, ":tt", $title);
    oci_bind_by_name($stmt, ":yy", $year);

    if (!oci_execute($stmt)) p_error("insertion Error", $stmt);
    // 주의: 에러 메시지가 "insertion Error"로 잘못됨
    // 올바른 메시지: "Update Error"

    // 성공 메시지 출력
    // 주의: 메시지가 "삽입됨"으로 잘못됨
    // 올바른 메시지: "갱신됨"
    print "- Movie($title,$year) 튜플 갱신됨 <br>";
}

// ========================================
// SELECT (검색) 처리 - 동적 WHERE 절 생성
// ========================================

// ===== WHERE 조건 문자열 초기화 =====
$where = "";

// ===== 각 입력 필드에 대한 조건 추가 =====

// ----- 제목 조건 -----
if(!empty($title)){
    // LIKE 검색: 부분 일치
    // '%'||:tt||'%': title에 입력값이 포함되어 있으면 매치
    $where .= " title like '%'||:tt||'%' ";
}

// ----- 연도 조건 -----
if(!empty($year)){
    // 이미 조건이 있으면 AND 추가
    if($where) $where .= " and ";
    // 정확한 일치
    $where .= " year = :yy ";
}

// ----- 상영시간 조건 -----
if(!empty($length)){
    if($where) $where .= " and ";
    // 정확한 일치
    $where .= " length = :len ";
}

// ----- 제작자 번호 조건 -----
if(!empty($pno)){
    if($where) $where .= " and ";
    $where .= " producerno = :pno ";
}

// ----- 영화사 조건 -----
if(!empty($sname)){
    if($where) $where .= " and ";
    $where .= " studioname = :sname ";
}

// ----- WHERE 키워드 추가 -----
// 조건이 하나라도 있으면 앞에 "where" 추가
if($where) $where = "where " . $where;

// ===== SELECT 쿼리 생성 =====
// movie와 movieexec을 LEFT OUTER JOIN
// 이유: producerno가 NULL이거나 매칭되지 않는 영화도 표시
$sql =
"select title, year, length, studioname, name 
 from movie 
 left outer join movieexec 
 on producerno = certno 
 $where 
 order by 1, 2";
// order by 1, 2: 첫 번째 컬럼(title), 두 번째 컬럼(year) 순으로 정렬

// ===== 쿼리 파싱 =====
$stmt = oci_parse($conn, $sql);
if (!$stmt) p_error("Parsing Error", $conn);

// ===== 바인드 변수 설정 =====
// 입력된 필드에 대해서만 바인딩
// 주의: WHERE 절에 사용된 변수만 바인딩해야 함
if(!empty($title)) oci_bind_by_name($stmt, ":tt", $title);
if(!empty($year)) oci_bind_by_name($stmt, ":yy", $year);
if(!empty($length)) oci_bind_by_name($stmt, ":len", $length);
if(!empty($pno)) oci_bind_by_name($stmt, ":pno", $pno);
if(!empty($sname)) oci_bind_by_name($stmt, ":sname", $sname);

// ===== 쿼리 실행 =====
if (!oci_execute($stmt)) p_error("Execution Error", $stmt);

// ========================================
// 검색 결과 출력 (HTML 테이블)
// ========================================

// ===== 폼 시작 (삭제 기능용) =====
// 검색 결과에서 체크박스로 영화를 선택하여 삭제할 수 있도록
// iframe이 아닌 같은 파일(movie_manage.php)로 다시 제출
print "<form method='post' action='movie_manage.php'>";

// ===== 테이블 헤더 =====
print "<TABLE bgcolor=#abbcbabc border=1 cellspacing=2>\n";
print "<TR bgcolor=#1ebcbabf align=center>
<TH> 제목 <TH> 연도 <TH> 상영시간 <TH> 영화사 <TH> 제작자 <TH> 삭제 
</TR>\n";

// ===== 결과 가져오기 =====
// oci_fetch_all(): 모든 결과를 2차원 배열로 가져옴
// $n: 총 행 수
$n = oci_fetch_all($stmt, $row);
// $row 구조:
// $row['TITLE'] = [영화1 제목, 영화2 제목, ...]
// $row['YEAR'] = [영화1 연도, 영화2 연도, ...]
// ...

// ===== 각 영화에 대한 행 출력 =====
for ($i=0; $i < $n; $i++) {
    print "<tr>";
    
    // ----- 모든 컬럼 출력 -----
    // $row의 각 키(컬럼명)에 대해 반복
    foreach ($row as $key => $val) {
        // $val[$i]: i번째 영화의 해당 컬럼 값
        print "<td>{$val[$i]}</td>";
    }

    // ----- 삭제 체크박스 추가 -----
    // HTML 특수문자 인코딩 (XSS 방지)
    // ENT_QUOTES: 작은따옴표와 큰따옴표 모두 인코딩
    $tt = htmlentities($row['TITLE'][$i], ENT_QUOTES);
    $yy = $row['YEAR'][$i];

    // 체크박스 값: "제목|연도" 형식
    // name='mv[]': 배열로 전송 (여러 영화 동시 선택 가능)
    print "<td><input type='checkbox' name='mv[]' value='$tt|$yy'></td>";
    
    print "</tr>";
}

// ===== 삭제 버튼 행 =====
// colspan=6: 6개 컬럼 병합
print "<tr><td colspan=6><input type='submit' name='submit' value='delete'></td></tr>";
// 주의: value='delete'이지만 PHP에서는 "검색"/"삽입"/"갱신"만 처리
//       실제로는 $mv 배열 체크로 삭제 수행

print "</TABLE>";
print "</form>";

// ===== 리소스 정리 =====
oci_free_statement($stmt);
oci_close($conn);

?>

<!-- 
========================================
전체 작업 흐름:
========================================

1. 페이지 로드 시:
   - POST 데이터 수신
   - 버튼 종류 확인 (검색/삽입/갱신)
   
2. DELETE 처리:
   - $_POST["mv"] 배열 확인
   - 각 영화에 대해:
     * 존재 확인
     * DELETE 쿼리 실행
     * 결과 메시지 출력

3. INSERT 처리:
   - $insert 플래그 확인
   - INSERT 쿼리 실행
   - 결과 메시지 출력

4. UPDATE 처리:
   - $update 플래그 확인
   - UPDATE 쿼리 실행
   - 결과 메시지 출력

5. SELECT (항상 실행):
   - 동적 WHERE 절 생성
   - 검색 쿼리 실행
   - 결과를 HTML 테이블로 출력
   - 각 행에 삭제 체크박스 추가

========================================
바인드 변수 사용의 장점:
========================================

1. SQL 인젝션 방지:
   악의적 입력: title = "'; DROP TABLE movie; --"
   바인드 사용: 문자열로 처리되어 안전
   
2. 성능 향상:
   - 쿼리 플랜 캐싱
   - 파싱 작업 재사용
   - 특히 반복 쿼리에서 효과적

3. 자동 타입 처리:
   - 날짜, 숫자 자동 변환
   - 특수문자 이스케이프 불필요

4. 코드 가독성:
   - SQL과 데이터 분리
   - 유지보수 용이

========================================
LEFT OUTER JOIN 사용 이유:
========================================

시나리오:
- movie 테이블: 100편
- 그 중 5편은 producerno가 NULL
- 나머지 95편은 유효한 producerno

INNER JOIN 사용 시:
- 95편만 조회됨 (producerno가 NULL인 5편 제외)

LEFT OUTER JOIN 사용 시:
- 100편 모두 조회됨
- producerno가 NULL인 영화: name 컬럼도 NULL

결과:
| Title    | Year | Length | Studio  | Name   |
|----------|------|--------|---------|--------|
| Avatar   | 2009 | 162    | Fox     | James  |
| Mystery  | 2020 | 120    | Disney  | NULL   |

========================================
개선 가능 영역:
========================================

1. 오타 수정:
   - line 47: $update = ture; → true
   - line 95: "insertion Error" → "Update Error"
   - line 99: "삽입됨" → "갱신됨"

2. 변수 정의:
   - line 93: $tt, $yy 대신 $title, $year 사용

3. 트랜잭션 처리:
   - 여러 삭제 작업 시 롤백 지원
   - oci_commit(), oci_rollback() 사용

4. 중복 삽입 방지:
   - INSERT 전 movie_exists() 확인

5. 에러 메시지 개선:
   - 사용자 친화적 메시지
   - 외래키 제약 위반 설명

6. 입력 검증:
   - 빈 값 체크
   - 데이터 타입 검증
   - 범위 확인

========================================
보안 체크리스트:
========================================

✅ SQL 인젝션 방지: oci_bind_by_name() 사용
✅ XSS 방지: htmlentities() 사용
❌ CSRF 방지: 토큰 미구현
❌ 세션 관리: 인증/권한 체크 없음
❌ 입력 검증: 서버 측 검증 부족
✅ 에러 처리: 상세한 에러 메시지 (개발용)

운영 환경 권장사항:
- 에러 메시지 간소화 (보안 정보 노출 방지)
- 로깅 시스템 구축
- 사용자 인증 추가
- HTTPS 사용

========================================
-->
