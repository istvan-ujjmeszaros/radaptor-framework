<?php

interface iEvent
{
	public function run(): void;
	public function abort(string $message = ''): void;
}
