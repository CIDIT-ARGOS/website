<?php

namespace api\Controller\Student;


require_once __DIR__ . "../Service/Student/StudentService.php";
require_once __DIR__ . "../../../Common/Constants/StatusCode.php";

use api\Service\Student\StudentService;
use Common\Constants\StatusCode;


class StudentController
{
    public function __construct(
        private StudentService $studentService
    ) {}

    public function get(): void
    {
        $id = isset($_GET['id'])
            ? (int) $_GET['id']
            : null;

        if ($id) {
            $student = $this->studentService->getById($id);

            if (!$student) {
                erro("Aluno não encontrado.", 404);
            }

            responder($student);
            http_response_code(StatusCode::OK);
            echo json_encode($student, JSON_UNESCAPED_UNICODE);
            return;
        }

        $filters = [
            'esquadrilha' => $_GET['esquadrilha'] ?? null,
            'especialidade' => $_GET['especialidade'] ?? null,
            'esquadrao' => $_GET['esquadrao'] ?? null,
            'curso' => $_GET['curso'] ?? null,
            'qrcode_hash' => $_GET['qrcode_hash'] ?? null,
            'incluir_inativos' => isset($_GET['incluir_inativos']) ? "ativo = 1" : null
        ];
        $tipos = isset($_GET['incluir_inativos']) ? "s" : null;

        $students = $this->studentService->getAll($filters);

        responder($students);
    }

    /*
    public function post(): void
    {
        $data = corpoJson();

        $student = $this->studentService->create($data);

        responder([
            'id' => $student
        ], 201);
    }

    public function put(): void
    {
        $id = isset($_GET['id'])
            ? (int) $_GET['id']
            : null;

        if (!$id) {
            erro("Informe o id do aluno (?id=).");
        }

        $data = corpoJson();

        $this->studentService->update($id, $data);

        responder([
            'atualizado' => true
        ]);
    }

    public function delete(): void
    {
        $id = isset($_GET['id'])
            ? (int) $_GET['id']
            : null;

        if (!$id) {
            erro("Informe o id do aluno (?id=).");
        }

        $this->studentService->disable($id);

        responder([
            'inativado' => true
        ]);
    }
        */
}
